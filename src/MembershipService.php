<?php
namespace App;

use PDO;

/**
 * Membership Lookups
 * Reads membership/tier/name data from the local app database (tgg_* tables) for
 * day-to-day runtime pages. Unlike CiviCRMImporter, this class never touches CiviCRM --
 * it only exists to serve traffic after the one-time import has already populated the
 * local tables.
 */
class MembershipService {

    /**
     * Get active membership tiers from local subscription plans
     * @return array
     * @throws Exception
     */
    public static function getMembershipTiers(): array {
        $appDb = Database::getAppConnection();
        $query = "SELECT p.civicrm_membership_type_id AS id, p.name, p.description, dr.price AS minimum_fee, p.duration_unit, p.duration_interval
                  FROM tgg_subscription_plans p
                  LEFT JOIN tgg_subscription_rates dr ON p.default_rate_id = dr.id
                  ORDER BY dr.price ASC";
        return $appDb->query($query)->fetchAll();
    }

    /**
     * Get details for a specific contact's membership from local database
     * @param int $contactId
     * @return array|null
     * @throws Exception
     */
    public static function getMemberMembershipDetails(int $contactId): ?array {
        $appDb = Database::getAppConnection();
        $query = "SELECT s.plan_id as membership_id, s.join_date, s.start_date, s.end_date, s.rate_id,
                         p.name as membership_name, COALESCE(r.price, dr.price) as minimum_fee,
                         COALESCE(r.price, dr.price) as price,
                         COALESCE(r.billing_frequency, p.duration_unit) as duration_unit,
                         COALESCE(CASE WHEN r.billing_frequency IS NOT NULL THEN 1 ELSE p.duration_interval END, p.duration_interval) as duration_interval,
                         p.guests_per_month,
                         CASE
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE() AND s.join_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 'New'
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE()) THEN 'Current'
                             WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 'Grace Period'
                             ELSE 'Expired'
                         END as status_label,
                         CASE
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE() AND s.join_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 'New'
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE()) THEN 'Current'
                             WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 'Grace Period'
                             ELSE 'Expired'
                         END as status_name,
                         CASE WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 1 ELSE 0 END as is_active
                  FROM tgg_subscriptions s
                  INNER JOIN tgg_subscription_plans p ON s.plan_id = p.id
                  LEFT JOIN tgg_subscription_rates r ON s.rate_id = r.id
                  LEFT JOIN tgg_subscription_rates dr ON p.default_rate_id = dr.id
                  WHERE s.contact_id = :contact_id
                  LIMIT 1";

        $stmt = $appDb->prepare($query);
        $stmt->execute(['contact_id' => $contactId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get a list of all members with membership status from local database
     * @return array
     * @throws Exception
     */
    public static function getMembersList(): array {
        $appDb = Database::getAppConnection();
        $query = "SELECT c.id, c.display_name, c.first_name, c.last_name, c.email, c.phone,
                         s.join_date, s.start_date, s.end_date,
                         p.name as membership_name,
                         CASE
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE() AND s.join_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 'New'
                             WHEN (s.status = 'active' AND s.end_date >= CURRENT_DATE()) THEN 'Current'
                             WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 'Grace Period'
                             ELSE 'Expired'
                         END as status_label,
                         CASE WHEN (s.status = 'active' AND s.end_date >= DATE_SUB(CURRENT_DATE(), INTERVAL (SELECT COALESCE(credits, 30) FROM tgg_volunteer_credits WHERE credit_key = 'renewal_grace_days' LIMIT 1) DAY)) THEN 1 ELSE 0 END as is_active
                  FROM tgg_contacts c
                  LEFT JOIN tgg_subscriptions s ON s.contact_id = c.id
                  LEFT JOIN tgg_subscription_plans p ON s.plan_id = p.id
                  WHERE c.is_deleted = 0
                  ORDER BY c.display_name ASC";
        $members = $appDb->query($query)->fetchAll();

        if (empty($members)) {
            return [];
        }

        // Resolve privacy display names
        $contactIds = array_column($members, 'id');
        $formattedNames = self::getFormattedNames($contactIds);

        foreach ($members as &$m) {
            $cid = (int)$m['id'];
            $m['display_name'] = $formattedNames[$cid] ?? $m['display_name'];
        }

        // Re-sort members by their privacy-aware display name
        usort($members, function($a, $b) {
            return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? '');
        });

        return $members;
    }

    /**
     * Get display names mapping from local contacts according to privacy preferences
     * @param array $contactIds
     * @return array
     */
    public static function getFormattedNames(array $contactIds): array {
        $contactIds = array_values(array_unique($contactIds));
        if (empty($contactIds)) {
            return [];
        }

        $appDb = Database::getAppConnection();

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));

        // Fetch local contact names
        $civiStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ({$placeholders})");
        $civiStmt->execute($contactIds);
        $civiContacts = $civiStmt->fetchAll(PDO::FETCH_UNIQUE);

        // Fetch custom display names from local settings
        $settingsStmt = $appDb->prepare("SELECT contact_id, custom_display_name FROM tgg_member_settings WHERE contact_id IN ({$placeholders})");
        $settingsStmt->execute($contactIds);
        $localSettings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $namesMap = [];
        foreach ($contactIds as $cid) {
            $cid = (int)$cid;
            $customName = $localSettings[$cid] ?? '';
            if (trim($customName) !== '') {
                $namesMap[$cid] = trim($customName);
            } elseif (isset($civiContacts[$cid])) {
                $namesMap[$cid] = $civiContacts[$cid]['display_name'];
            } else {
                $namesMap[$cid] = "Member #{$cid}";
            }
        }

        return $namesMap;
    }

    /**
     * Get display name for a single contact ID according to their privacy preferences.
     * @param int $contactId Contact ID
     * @return string Formatted display name
     */
    public static function getFormattedName(int $contactId): string {
        $map = self::getFormattedNames([$contactId]);
        return $map[$contactId] ?? "Member #{$contactId}";
    }
}
