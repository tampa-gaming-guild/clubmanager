<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Makes Rate the sole price source for a membership Plan, and gives Plan a real
 * "current/default rate" pointer instead of inferring it from the "{name} - Standard"
 * naming convention. Also adds the audit columns needed for admins to retire a rate
 * (moving grandfathered members onto the plan's current rate) as an explicit action,
 * never automatically -- see BillingHelper::retireRate().
 */
final class AddRateHistoryModel extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        // 1. New columns. default_rate_id starts nullable (and stays nullable at the
        // schema level, since ON DELETE SET NULL needs somewhere to fall back to) but
        // the app enforces every active plan has one set.
        $this->table('tgg_subscription_plans')
            ->addColumn('default_rate_id', 'integer', ['null' => true, 'after' => 'active'])
            ->addForeignKey('default_rate_id', 'tgg_subscription_rates', 'id', ['delete' => 'SET_NULL', 'constraint' => 'fk_plan_default_rate'])
            ->update();

        $this->table('tgg_subscription_rates')
            ->addColumn('created_at', 'datetime', ['null' => true, 'after' => 'expiration_date'])
            ->addColumn('retired_at', 'datetime', ['null' => true, 'after' => 'created_at'])
            ->update();

        $this->table('tgg_billing_ledger')
            ->addColumn('rate_id', 'integer', ['null' => true, 'after' => 'plan_id'])
            ->addForeignKey('rate_id', 'tgg_subscription_rates', 'id', ['delete' => 'SET_NULL', 'constraint' => 'fk_ledger_rate'])
            ->update();

        // 2. Backfill created_at for existing rates -- true historical effective dates
        // aren't known, so this is a best-effort "as of this migration" timestamp.
        $this->execute("UPDATE tgg_subscription_rates SET created_at = NOW() WHERE created_at IS NULL");

        // 3. Point every plan at its existing auto-managed "{name} - Standard" rate. If
        // one is somehow missing (shouldn't happen -- one was created per plan by both
        // the subscription-rates migration and CiviCRMImporter/savePlan), create it now
        // from the plan's current price, since that column still exists at this point
        // in the migration.
        $plans = $this->fetchAll("SELECT id, name, price, duration_unit FROM tgg_subscription_plans");
        $findRateStmt = $pdo->prepare("SELECT id FROM tgg_subscription_rates WHERE plan_id = :plan_id AND name = :name LIMIT 1");
        $insertRateStmt = $pdo->prepare("
            INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, created_at)
            VALUES (:plan_id, :name, :price, :billing_frequency, 0, NOW())
        ");
        $setDefaultStmt = $pdo->prepare("UPDATE tgg_subscription_plans SET default_rate_id = :rate_id WHERE id = :plan_id");

        foreach ($plans as $plan) {
            $standardName = $plan['name'] . ' - Standard';
            $findRateStmt->execute(['plan_id' => $plan['id'], 'name' => $standardName]);
            $rateId = $findRateStmt->fetchColumn();

            if (!$rateId) {
                $freq = 'monthly';
                if ($plan['duration_unit'] === 'year') {
                    $freq = 'annual';
                } elseif ($plan['duration_unit'] === 'day') {
                    $freq = 'daily';
                }
                $insertRateStmt->execute([
                    'plan_id' => $plan['id'],
                    'name' => $standardName,
                    'price' => $plan['price'],
                    'billing_frequency' => $freq,
                ]);
                $rateId = $pdo->lastInsertId();
            }

            $setDefaultStmt->execute(['rate_id' => $rateId, 'plan_id' => $plan['id']]);
        }

        // 4. Pin every subscription to an explicit rate -- after this, rate_id should
        // never be NULL for a normal subscription (only via a future hard-deleted rate,
        // which the app avoids -- rates.php only allows deleting unused rates).
        $this->execute("
            UPDATE tgg_subscriptions s
            JOIN tgg_subscription_plans p ON s.plan_id = p.id
            SET s.rate_id = p.default_rate_id
            WHERE s.rate_id IS NULL AND p.default_rate_id IS NOT NULL
        ");

        // 5. Rate is now the sole price source for a plan.
        $this->table('tgg_subscription_plans')
            ->removeColumn('price')
            ->update();
    }

    public function down(): void
    {
        $this->table('tgg_subscription_plans')
            ->addColumn('price', 'decimal', ['precision' => 20, 'scale' => 2, 'null' => false, 'default' => 0, 'after' => 'description'])
            ->update();

        $this->execute("
            UPDATE tgg_subscription_plans p
            JOIN tgg_subscription_rates r ON p.default_rate_id = r.id
            SET p.price = r.price
        ");

        $this->table('tgg_subscription_plans')
            ->dropForeignKey('default_rate_id')
            ->removeColumn('default_rate_id')
            ->update();

        $this->table('tgg_billing_ledger')
            ->dropForeignKey('rate_id')
            ->removeColumn('rate_id')
            ->update();

        $this->table('tgg_subscription_rates')
            ->removeColumn('created_at')
            ->removeColumn('retired_at')
            ->update();
    }
}
