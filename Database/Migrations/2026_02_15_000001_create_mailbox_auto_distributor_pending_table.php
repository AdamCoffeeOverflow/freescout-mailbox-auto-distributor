<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMailboxAutoDistributorPendingTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('mailbox_auto_distributor_pending')) {
            return;
        }

        Schema::create('mailbox_auto_distributor_pending', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('mailbox_id')->index();
            $table->unsignedInteger('conversation_id')->unique();
            $table->dateTime('run_at')->index();
            $table->string('status', 20)->default('pending')->index(); // pending|assigned|skipped|failed
            $table->dateTime('processed_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mailbox_auto_distributor_pending');
    }
}
