<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSubscriptionRates extends AbstractMigration
{
    private const TABLE_OPTS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'signed' => true];

    public function up(): void
    {
        // 1. Create tgg_subscription_rates
        $this->table('tgg_subscription_rates', self::TABLE_OPTS)
            ->addColumn('plan_id', 'integer', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('price', 'decimal', ['precision' => 20, 'scale' => 2, 'null' => false])
            ->addColumn('billing_frequency', 'string', ['limit' => 50, 'null' => false]) // 'annual', 'monthly', 'daily'
            ->addColumn('inactive', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('expiration_date', 'date', ['null' => true])
            ->addForeignKey('plan_id', 'tgg_subscription_plans', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_rates_plan'])
            ->create();

        // 2. Add rate_id column to tgg_subscriptions
        $this->table('tgg_subscriptions')
            ->addColumn('rate_id', 'integer', ['null' => true])
            ->addForeignKey('rate_id', 'tgg_subscription_rates', 'id', ['delete' => 'SET_NULL', 'constraint' => 'fk_sub_rate'])
            ->update();

        // 3. Seed initial rates from current plans and associate existing subscriptions
        $plans = $this->fetchAll("SELECT * FROM tgg_subscription_plans");
        foreach ($plans as $plan) {
            $freq = 'monthly';
            if ($plan['duration_unit'] === 'year') {
                $freq = 'annual';
            } elseif ($plan['duration_unit'] === 'day') {
                $freq = 'daily';
            }
            
            // Insert into tgg_subscription_rates
            $this->execute(sprintf(
                "INSERT INTO tgg_subscription_rates (plan_id, name, price, billing_frequency, inactive, expiration_date) VALUES (%d, '%s', %f, '%s', 0, NULL)",
                $plan['id'],
                $plan['name'] . " - Standard",
                $plan['price'],
                $freq
            ));
            
            // Get the inserted rate ID
            $rateId = $this->fetchRow(sprintf(
                "SELECT id FROM tgg_subscription_rates WHERE plan_id = %d AND price = %f AND billing_frequency = '%s' LIMIT 1",
                $plan['id'],
                $plan['price'],
                $freq
            ))['id'];
            
            // Update existing subscriptions for this plan
            $this->execute(sprintf(
                "UPDATE tgg_subscriptions SET rate_id = %d WHERE plan_id = %d",
                $rateId,
                $plan['id']
            ));
        }
    }

    public function down(): void
    {
        $this->table('tgg_subscriptions')
            ->dropForeignKey('rate_id')
            ->removeColumn('rate_id')
            ->update();

        $this->table('tgg_subscription_rates')->drop()->save();
    }
}
