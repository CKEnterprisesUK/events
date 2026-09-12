<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend the `support_requests.category` enum with an `abuse` value so
     * public "Report this event" submissions from the event page can be filed
     * as a distinct, triageable bucket (rather than lumped into `other`).
     *
     * The column is a MySQL ENUM, so the permitted value set must be widened at
     * the database level; a raw MODIFY COLUMN is used because Laravel's schema
     * builder cannot alter an enum's value set in place. The default (`other`)
     * is preserved.
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `support_requests` '
            .'MODIFY COLUMN `category` ENUM('
            ."'account','events','orders','payments','billing','technical','abuse','other'"
            .") NOT NULL DEFAULT 'other'"
        );
    }

    /**
     * Reverse the migration. Any rows already filed as `abuse` are folded back
     * into `other` before the value is removed so the narrower enum accepts
     * every existing row.
     */
    public function down(): void
    {
        DB::statement("UPDATE `support_requests` SET `category` = 'other' WHERE `category` = 'abuse'");

        DB::statement(
            'ALTER TABLE `support_requests` '
            .'MODIFY COLUMN `category` ENUM('
            ."'account','events','orders','payments','billing','technical','other'"
            .") NOT NULL DEFAULT 'other'"
        );
    }
};
