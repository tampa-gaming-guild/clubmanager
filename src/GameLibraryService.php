<?php
namespace App;

use PDO;
use Exception;

/**
 * Board game library orchestration: the DB-facing service the library.php
 * gallery page's librarian controls call. Every mutating method writes
 * tgg_games and commits FIRST, then best-effort pushes the change out to the
 * live BGG collection via BggCollectionSync -- a push failure only updates
 * bgg_sync_status/bgg_last_sync_error and never rolls back or blocks the
 * local write, since ClubManager (not BGG) is the source of truth.
 */
class GameLibraryService {

    /**
     * @param array $data bgg_id, name, year_published, thumbnail_url, image_url, description,
     *                     min_players, max_players, min_playtime, max_playtime, min_age,
     *                     bgg_rating_bayes, bgg_weight, mechanisms (array), categories (array),
     *                     notes, owner_contact_id (nullable)
     * @throws Exception
     */
    public static function addGame(array $data, int $actorContactId): array {
        $appDb = Database::getAppConnection();

        $bggId = (int)$data['bgg_id'];
        $existingStmt = $appDb->prepare("SELECT id, name, is_deleted FROM tgg_games WHERE bgg_id = :bgg_id LIMIT 1");
        $existingStmt->execute(['bgg_id' => $bggId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['is_deleted']) {
                throw new Exception("\"{$existing['name']}\" was previously removed from the library. Restoring a removed game isn't supported yet -- contact a superadmin.");
            }
            throw new Exception("\"{$existing['name']}\" is already in the library.");
        }

        $stmt = $appDb->prepare("
            INSERT INTO tgg_games (
                bgg_id, name, year_published, thumbnail_url, image_url, description,
                min_players, max_players, min_playtime, max_playtime, min_age,
                bgg_rating_bayes, bgg_weight, mechanisms, categories, notes,
                owner_contact_id, loan_started_at, added_by_contact_id, bgg_sync_status
            ) VALUES (
                :bgg_id, :name, :year_published, :thumbnail_url, :image_url, :description,
                :min_players, :max_players, :min_playtime, :max_playtime, :min_age,
                :bgg_rating_bayes, :bgg_weight, :mechanisms, :categories, :notes,
                :owner_contact_id, :loan_started_at, :added_by_contact_id, 'pending'
            )
        ");
        $ownerContactId = !empty($data['owner_contact_id']) ? (int)$data['owner_contact_id'] : null;
        $stmt->execute([
            'bgg_id' => (int)$data['bgg_id'],
            'name' => $data['name'],
            'year_published' => $data['year_published'] ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'description' => $data['description'] ?? null,
            'min_players' => $data['min_players'] ?? null,
            'max_players' => $data['max_players'] ?? null,
            'min_playtime' => $data['min_playtime'] ?? null,
            'max_playtime' => $data['max_playtime'] ?? null,
            'min_age' => $data['min_age'] ?? null,
            'bgg_rating_bayes' => $data['bgg_rating_bayes'] ?? null,
            'bgg_weight' => $data['bgg_weight'] ?? null,
            'mechanisms' => json_encode($data['mechanisms'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'categories' => json_encode($data['categories'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'notes' => $data['notes'] ?? null,
            'owner_contact_id' => $ownerContactId,
            'loan_started_at' => $ownerContactId !== null ? date('Y-m-d H:i:s') : null,
            'added_by_contact_id' => $actorContactId,
        ]);
        $gameId = (int)$appDb->lastInsertId();

        AuditLog::log('library', 'game_added', [
            'game_id' => $gameId,
            'bgg_id' => (int)$data['bgg_id'],
            'name' => $data['name'],
        ], null, $actorContactId);

        if ($ownerContactId !== null) {
            AuditLog::log('library', 'loan_recorded', ['game_id' => $gameId], $ownerContactId, $actorContactId);
            self::sendLoanThankYouEmail($ownerContactId, $data['name']);
        }

        self::pushToBgg($gameId);

        return ['game_id' => $gameId];
    }

    /**
     * @param array $data Same shape as addGame(), all fields optional -- only
     *                     provided keys are updated.
     * @throws Exception
     */
    public static function updateGame(int $gameId, array $data, int $actorContactId): array {
        $appDb = Database::getAppConnection();

        $beforeStmt = $appDb->prepare("SELECT name, owner_contact_id FROM tgg_games WHERE id = :id LIMIT 1");
        $beforeStmt->execute(['id' => $gameId]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            throw new Exception("Game not found.");
        }
        $previousOwnerId = $before['owner_contact_id'] !== null ? (int)$before['owner_contact_id'] : null;

        $fieldMap = [
            'name' => 'name', 'year_published' => 'year_published', 'thumbnail_url' => 'thumbnail_url',
            'image_url' => 'image_url', 'description' => 'description', 'min_players' => 'min_players',
            'max_players' => 'max_players', 'min_playtime' => 'min_playtime', 'max_playtime' => 'max_playtime',
            'min_age' => 'min_age', 'notes' => 'notes',
        ];
        $sets = [];
        $params = ['id' => $gameId];
        foreach ($fieldMap as $key => $column) {
            if (array_key_exists($key, $data)) {
                $sets[] = "{$column} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('mechanisms', $data)) {
            $sets[] = "mechanisms = :mechanisms";
            $params['mechanisms'] = json_encode($data['mechanisms'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('categories', $data)) {
            $sets[] = "categories = :categories";
            $params['categories'] = json_encode($data['categories'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $newOwnerId = array_key_exists('owner_contact_id', $data)
            ? (!empty($data['owner_contact_id']) ? (int)$data['owner_contact_id'] : null)
            : $previousOwnerId;
        $ownerChanged = array_key_exists('owner_contact_id', $data) && $newOwnerId !== $previousOwnerId;
        if ($ownerChanged) {
            $sets[] = "owner_contact_id = :owner_contact_id";
            $params['owner_contact_id'] = $newOwnerId;
            $sets[] = "loan_started_at = :loan_started_at";
            $params['loan_started_at'] = $newOwnerId !== null ? date('Y-m-d H:i:s') : null;
        }

        if (!empty($sets)) {
            $appDb->prepare("UPDATE tgg_games SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        }

        $gameName = $data['name'] ?? $before['name'];

        AuditLog::log('library', 'game_updated', [
            'game_id' => $gameId,
            'changed_fields' => array_keys($data),
        ], null, $actorContactId);

        if ($ownerChanged) {
            if ($newOwnerId !== null) {
                AuditLog::log('library', 'loan_recorded', ['game_id' => $gameId], $newOwnerId, $actorContactId);
                self::sendLoanThankYouEmail($newOwnerId, $gameName);
            } elseif ($previousOwnerId !== null) {
                AuditLog::log('library', 'loan_returned', ['game_id' => $gameId], $previousOwnerId, $actorContactId);
            }
        }

        self::pushToBgg($gameId);

        return ['game_id' => $gameId];
    }

    public static function removeGame(int $gameId, int $actorContactId): array {
        $appDb = Database::getAppConnection();

        $stmt = $appDb->prepare("SELECT name, bgg_id FROM tgg_games WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$game) {
            throw new Exception("Game not found.");
        }

        $appDb->prepare("UPDATE tgg_games SET is_deleted = 1 WHERE id = :id")->execute(['id' => $gameId]);

        AuditLog::log('library', 'game_removed', [
            'game_id' => $gameId,
            'name' => $game['name'],
        ], null, $actorContactId);

        $result = BggCollectionSync::removeGame(['bgg_id' => (int)$game['bgg_id']]);
        self::recordSyncResult($gameId, $result);

        return ['success' => true];
    }

    /** Re-attempt the BGG push for one game's current local state. */
    public static function retryBggSync(int $gameId): array {
        return self::pushToBgg($gameId);
    }

    /** @throws Exception */
    private static function sendLoanThankYouEmail(int $contactId, string $gameName): void {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT email FROM tgg_contacts WHERE id = :id AND is_deleted = 0 LIMIT 1");
        $stmt->execute(['id' => $contactId]);
        $email = $stmt->fetchColumn();
        if (!$email) {
            return; // no valid contact/email on file, nothing to send
        }

        try {
            MailHelper::sendTemplate($email, 'game_loan_thank_you', [
                'display_name' => MembershipService::getFormattedName($contactId),
                'game_name' => $gameName,
            ], $contactId);
        } catch (\Throwable $e) {
            // Matches AuditLog's own fail-open convention: an email hiccup must
            // never block the loan record itself.
            error_log("Failed to send game_loan_thank_you to contact {$contactId}: " . $e->getMessage());
        }
    }

    private static function pushToBgg(int $gameId): array {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT bgg_id, name, notes FROM tgg_games WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$game) {
            return ['success' => false, 'message' => 'Game not found'];
        }

        // Deliberately does NOT include who a game is on loan from: that comment
        // field is publicly visible on the BGG collection page, and loan/owner
        // data is librarian-only in ClubManager. ClubManager is the source of
        // truth for that now, so it has no need to also live in a BGG comment.
        $result = BggCollectionSync::pushGame([
            'bgg_id' => (int)$game['bgg_id'],
            'name' => $game['name'],
            'comment' => $game['notes'],
        ]);
        self::recordSyncResult($gameId, $result);
        return $result;
    }

    /** @param array{success: bool, message: ?string} $result */
    private static function recordSyncResult(int $gameId, array $result): void {
        $appDb = Database::getAppConnection();
        if ($result['success']) {
            $appDb->prepare("
                UPDATE tgg_games SET bgg_sync_status = 'synced', bgg_last_synced_at = NOW(), bgg_last_sync_error = NULL
                WHERE id = :id
            ")->execute(['id' => $gameId]);
        } else {
            $appDb->prepare("
                UPDATE tgg_games SET bgg_sync_status = 'failed', bgg_last_sync_error = :error
                WHERE id = :id
            ")->execute(['id' => $gameId, 'error' => $result['message']]);
            AuditLog::log('library', 'bgg_push_failed', ['game_id' => $gameId, 'error' => $result['message']]);
        }
    }
}
