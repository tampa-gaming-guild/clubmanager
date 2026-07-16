<?php
namespace App;

use Throwable;

/**
 * Audit Log Service
 *
 * Writes governance/security events to tgg_audit_log and resolves the acting
 * identity for the denormalized actor columns on the financial ledgers
 * (tgg_billing_ledger / tgg_volunteer_credit_transactions).
 *
 * Actor model:
 *  - actor/created_by       = the acting session identity ($_SESSION['user']).
 *    During "Login As" this is the impersonated member.
 *  - impersonator           = the real admin behind an impersonation session
 *    ($_SESSION['impersonator']), NULL otherwise. The true responsible person
 *    is always impersonator ?? actor.
 *  - source                 = web | stripe | cron | import. A NULL actor with
 *    source 'cron' is the autorenew job ("system"); NULL/NULL is a legacy row.
 *
 * log() never throws: like MailHelper's tgg_email_log insert, an audit-write
 * failure is error_log()ed and swallowed so it can never break the business
 * action it describes.
 */
class AuditLog {
    public const SOURCE_WEB = 'web';
    public const SOURCE_STRIPE = 'stripe';
    public const SOURCE_CRON = 'cron';
    public const SOURCE_IMPORT = 'import';

    /**
     * Record one audit event.
     *
     * @param string $category Fixed category: security|roles|rates|volunteer_config|membership|import
     * @param string $action Event name, e.g. 'rate_updated', 'impersonation_start'
     * @param array $details Extra context, JSON-encoded. Never include secrets
     *                       (passwords, tokens, reset codes).
     * @param int|null $targetContactId Member the action was about, if any
     * @param int|null $actorContactId Explicit actor override for flows with no
     *                                 (or the wrong) session identity: CLI, token
     *                                 links, webhook. Null = resolve from session.
     * @param string|null $source Override; null = defaultSource()
     */
    public static function log(
        string $category,
        string $action,
        array $details = [],
        ?int $targetContactId = null,
        ?int $actorContactId = null,
        ?string $source = null
    ): void {
        try {
            $appDb = Database::getAppConnection();
            $stmt = $appDb->prepare("
                INSERT INTO tgg_audit_log
                    (category, action, actor_contact_id, impersonator_contact_id, target_contact_id, source, details, ip_address)
                VALUES
                    (:category, :action, :actor_contact_id, :impersonator_contact_id, :target_contact_id, :source, :details, :ip_address)
            ");
            $stmt->execute([
                'category' => $category,
                'action' => $action,
                'actor_contact_id' => $actorContactId ?? self::actingContactId(),
                // Override cases (CLI/webhook/token links) have no impersonation
                // session, so the session-resolved impersonator is correct either way.
                'impersonator_contact_id' => self::impersonatorContactId(),
                'target_contact_id' => $targetContactId,
                'source' => $source ?? self::defaultSource(),
                'details' => empty($details) ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log("Failed to write tgg_audit_log ({$category}/{$action}): " . $e->getMessage());
        }
    }

    /**
     * The acting session identity (the swapped-in member during impersonation).
     * Null under CLI (bootstrap never starts a session there) or anonymous pages.
     */
    public static function actingContactId(): ?int {
        $id = $_SESSION['user']['contact_id'] ?? null;
        return $id !== null ? (int)$id : null;
    }

    /**
     * The real admin behind an impersonation session, or null.
     */
    public static function impersonatorContactId(): ?int {
        $id = $_SESSION['impersonator']['contact_id'] ?? null;
        return $id !== null ? (int)$id : null;
    }

    /**
     * 'cron' under CLI, 'web' otherwise.
     */
    public static function defaultSource(): string {
        return PHP_SAPI === 'cli' ? self::SOURCE_CRON : self::SOURCE_WEB;
    }

    /**
     * Plain-text label for a ledger row's actor columns, shared by every view
     * that shows a "Recorded By" column. Callers must escape with e().
     *
     * @param array<int|string, string> $namesById contact_id => display_name
     */
    public static function describeActor(?int $createdBy, ?int $impersonatorId, ?string $source, array $namesById): string {
        if ($createdBy !== null) {
            $actorName = $namesById[$createdBy] ?? "Member #{$createdBy}";
            if ($impersonatorId !== null) {
                $impName = $namesById[$impersonatorId] ?? "Member #{$impersonatorId}";
                return "{$impName} (as {$actorName})";
            }
            return $source === self::SOURCE_STRIPE ? "{$actorName} (via Stripe)" : $actorName;
        }
        if ($source === self::SOURCE_CRON) {
            return 'System (auto-renew)';
        }
        if ($source === self::SOURCE_IMPORT) {
            return 'Import';
        }
        return '—';
    }

    /**
     * The three denormalized actor values for a ledger INSERT.
     *
     * @param int|null $actorOverride Replaces the session-resolved actor
     * @param string|null $source Replaces defaultSource()
     * @return array{created_by: ?int, impersonator_id: ?int, source: string}
     */
    public static function actorColumns(?int $actorOverride = null, ?string $source = null): array {
        return [
            'created_by' => $actorOverride ?? self::actingContactId(),
            'impersonator_id' => self::impersonatorContactId(),
            'source' => $source ?? self::defaultSource(),
        ];
    }
}
