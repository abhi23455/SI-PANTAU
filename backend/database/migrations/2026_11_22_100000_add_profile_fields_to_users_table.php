<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 15)->nullable();
            $table->string('address', 1000)->nullable();
            $table->string('institution')->nullable();
            $table->string('major')->nullable();
            $table->string('role', 32)->default('pemagang');
            $table->string('status', 32)->default('pending');
            $table->longText('avatar_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'address',
                'institution',
                'major',
                'role',
                'status',
                'avatar_url',
            ]);
        });
    }
};
