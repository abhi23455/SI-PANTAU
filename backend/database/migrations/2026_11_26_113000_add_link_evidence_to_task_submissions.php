<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->string('file_path')->nullable()->change();
            $table->string('file_name')->nullable()->change();
            $table->string('file_type', 128)->nullable()->change();
            $table->text('evidence_url')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('task_submissions')->whereNotNull('evidence_url')->exists()) {
            throw new RuntimeException('Cannot remove link evidence while task submissions still contain links.');
        }

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropColumn('evidence_url');
            $table->string('file_path')->nullable(false)->change();
            $table->string('file_name')->nullable(false)->change();
            $table->string('file_type', 128)->nullable(false)->change();
        });
    }
};
