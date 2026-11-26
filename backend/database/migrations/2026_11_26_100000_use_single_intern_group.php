<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->index();
        });

        DB::transaction(function () {
            $defaultGroup = DB::table('groups')->where('is_default', true)->first();
            $adminId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

            if (! $defaultGroup) {
                $defaultGroup = DB::table('groups')->where('is_deleted', false)->orderBy('id')->first();
            }

            if ($defaultGroup) {
                $groupId = $defaultGroup->id;
                DB::table('groups')->where('id', $groupId)->update([
                    'name' => 'Kelompok Magang BPS Semarang',
                    'description' => 'Kelompok bersama untuk seluruh pemagang aktif.',
                    'mentor_id' => null,
                    'is_default' => true,
                    'is_deleted' => false,
                    'updated_at' => now(),
                ]);
            } else {
                $groupId = DB::table('groups')->insertGetId([
                    'name' => 'Kelompok Magang BPS Semarang',
                    'description' => 'Kelompok bersama untuk seluruh pemagang aktif.',
                    'mentor_id' => null,
                    'created_by' => $adminId,
                    'is_default' => true,
                    'is_deleted' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('tasks')->whereNotNull('group_id')->update(['group_id' => $groupId]);
            DB::table('activity_logs')->whereNotNull('group_id')->update(['group_id' => $groupId]);

            $activeInternIds = DB::table('users')
                ->whereIn('role', ['pemagang', 'intern'])
                ->where('status', 'active')
                ->pluck('id');
            foreach ($activeInternIds as $userId) {
                DB::table('group_members')->insertOrIgnore([
                    'group_id' => $groupId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('groups')
                ->where('id', '!=', $groupId)
                ->update(['is_deleted' => true, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['is_default']);
            $table->dropColumn('is_default');
        });
    }
};
