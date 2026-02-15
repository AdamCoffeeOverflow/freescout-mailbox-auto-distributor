<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMailboxAutoDistributorAuditTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('mailbox_auto_distributor_audit')) {
            return;
        }

        Schema::create('mailbox_auto_distributor_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('mailbox_id')->index();
            $table->unsignedInteger('conversation_id')->index();
            $table->unsignedInteger('assigned_user_id')->nullable()->index();
            $table->string('action', 30)->index(); // enqueued|assigned|skipped|failed
            $table->string('mode', 30)->nullable()->index(); // sticky|round_robin|least_open|deferred
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['mailbox_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('mailbox_auto_distributor_audit');
    }
}
