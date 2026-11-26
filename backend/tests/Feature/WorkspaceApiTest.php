<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AccountStatusNotification;
use App\Notifications\TaskNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_members_can_view_group_tasks_and_add_comments(): void
    {
        $mentor = $this->user('mentor', 'active');
        $member = $this->user('pemagang', 'active', 'Ayu Pratama');
        $group = Group::create([
            'name' => 'Tim Statistik',
            'mentor_id' => $mentor->id,
            'created_by' => $mentor->id,
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id]);
        $task = Task::create([
            'group_id' => $group->id,
            'created_by' => $mentor->id,
            'assigned_to' => $member->id,
            'title' => 'Validasi data',
            'status' => 'todo',
        ]);

        $this->actingAs($member)->getJson("/api/groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('tasks.0.title', 'Validasi data');

        $this->actingAs($member)->postJson("/api/tasks/{$task->id}/comments", [
            'content' => 'Saya mulai mengerjakan.',
        ])->assertCreated()
            ->assertJsonPath('content', 'Saya mulai mengerjakan.')
            ->assertJsonPath('user.full_name', 'Ayu Pratama');
    }

    public function test_only_group_managers_can_create_or_change_task_status(): void
    {
        $mentor = $this->user('mentor', 'active');
        $member = $this->user('pemagang', 'active', 'Ayu Pratama');
        $group = Group::create([
            'name' => 'Tim Statistik',
            'mentor_id' => $mentor->id,
            'created_by' => $mentor->id,
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id]);

        $this->actingAs($member)->postJson('/api/tasks', [
            'group_id' => $group->id,
            'title' => 'Validasi data',
            'description' => 'Cocokkan tabel survei.',
            'due_date' => '2026-12-01',
            'priority' => 'high',
            'orang' => ['A'],
        ])->assertForbidden();

        $created = $this->actingAs($mentor)->postJson('/api/tasks', [
            'group_id' => $group->id,
            'title' => 'Validasi data',
            'description' => 'Cocokkan tabel survei.',
            'due_date' => '2026-12-01',
            'priority' => 'high',
            'assigned_to' => $member->id,
            'orang' => ['A'],
        ])->assertCreated()
            ->assertJsonPath('title', 'Validasi data')
            ->assertJsonPath('task_assignees.0.member_initial', 'A')
            ->assertJsonPath('assigned_to', $member->id);

        $taskId = $created->json('id');
        $this->actingAs($member)->patchJson("/api/tasks/{$taskId}", [
            'status' => 'in_progress',
        ])->assertForbidden();

        $this->actingAs($mentor)->patchJson("/api/tasks/{$taskId}", [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('status', 'in_progress');

        $this->assertDatabaseHas('task_history', [
            'task_id' => $taskId,
            'old_status' => 'todo',
            'new_status' => 'in_progress',
        ]);
    }

    public function test_unrelated_user_cannot_read_or_change_a_group(): void
    {
        $mentor = $this->user('mentor', 'active');
        $stranger = $this->user('pemagang', 'active');
        $group = Group::create([
            'name' => 'Rahasia',
            'mentor_id' => $mentor->id,
            'created_by' => $mentor->id,
        ]);

        $this->actingAs($stranger)->getJson("/api/groups/{$group->id}")->assertForbidden();
        $this->actingAs($stranger)->patchJson("/api/groups/{$group->id}", [
            'name' => 'Diubah',
        ])->assertForbidden();
    }

    public function test_admin_can_create_and_verify_accounts(): void
    {
        Notification::fake();
        $admin = $this->user('admin', 'active');

        $created = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Dina',
            'email' => 'dina@example.com',
            'password' => 'password123',
            'role' => 'pemagang',
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$created->json('id'), [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('status', 'active');
        $this->assertDatabaseHas('group_members', [
            'group_id' => \App\Models\Group::where('is_default', true)->value('id'),
            'user_id' => $created->json('id'),
        ]);

        Notification::assertSentTo(
            User::find($created->json('id')),
            AccountStatusNotification::class
        );
    }

    public function test_workspace_uses_one_shared_group_for_active_interns(): void
    {
        $admin = $this->user('admin', 'active');
        $intern = $this->user('pemagang', 'pending');
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$intern->id, ['status' => 'active'])
            ->assertOk();

        $this->actingAs($admin)->getJson('/api/groups')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.is_default', true)
            ->assertJsonPath('0.name', 'Kelompok Magang BPS Semarang');

        $this->assertDatabaseHas('group_members', [
            'group_id' => Group::where('is_default', true)->value('id'),
            'user_id' => $intern->id,
        ]);

        $this->actingAs($admin)->postJson('/api/groups', [
            'name' => 'Kelompok tambahan',
        ])->assertCreated();
    }

    public function test_mentor_can_monitor_the_shared_group_without_a_personal_group_assignment(): void
    {
        $admin = $this->user('admin', 'active');
        $mentor = $this->user('mentor', 'active');
        $intern = $this->user('pemagang', 'pending');
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$intern->id, ['status' => 'active'])
            ->assertOk();

        $group = Group::where('is_default', true)->firstOrFail();
        $task = Task::create([
            'group_id' => $group->id,
            'created_by' => $admin->id,
            'assigned_to' => $intern->id,
            'title' => 'Memeriksa data',
            'status' => 'todo',
        ]);

        $this->actingAs($mentor)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('0.title', 'Memeriksa data');
        $this->actingAs($mentor)->getJson('/api/dashboard/task-report?group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('summary.total', 1);
        $this->actingAs($mentor)->getJson('/api/dashboard/personal-stats')
            ->assertOk()
            ->assertJsonPath('scheduled', 1);
        $this->actingAs($mentor)->getJson('/api/activity-logs?group_id='.$group->id)
            ->assertOk();

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$intern->id, ['status' => 'rejected'])
            ->assertOk();
        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group->id,
            'user_id' => $intern->id,
        ]);
    }

    public function test_managers_can_create_groups_manage_members_and_preserve_assigned_task_access(): void
    {
        Notification::fake();
        Storage::fake('local');
        $admin = $this->user('admin', 'active');
        $mentor = $this->user('mentor', 'active');
        $intern = $this->user('pemagang', 'active');
        $sharedGroup = Group::where('is_default', true)->firstOrFail();

        $created = $this->actingAs($mentor)->postJson('/api/groups', [
            'name' => 'Tim Survei',
        ])->assertCreated()
            ->assertJsonPath('name', 'Tim Survei')
            ->assertJsonPath('mentor_id', $mentor->id);
        $groupId = $created->json('id');

        $this->actingAs($mentor)->postJson("/api/groups/{$groupId}/members", [
            'user_id' => $intern->id,
        ])->assertCreated();
        $this->actingAs($mentor)->getJson('/api/groups')
            ->assertOk()
            ->assertJsonCount(2);

        $this->actingAs($admin)->postJson("/api/groups/{$sharedGroup->id}/members", [
            'user_id' => $intern->id,
        ])->assertCreated();
        $this->actingAs($intern)->getJson('/api/groups')
            ->assertOk()
            ->assertJsonCount(2);
        $this->actingAs($admin)->deleteJson("/api/groups/{$sharedGroup->id}/members/{$intern->id}")
            ->assertOk();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$intern->id, ['status' => 'active'])
            ->assertOk();
        $this->assertDatabaseMissing('group_members', [
            'group_id' => $sharedGroup->id,
            'user_id' => $intern->id,
        ]);

        $task = Task::create([
            'group_id' => $groupId,
            'created_by' => $mentor->id,
            'assigned_to' => $intern->id,
            'title' => 'Mengolah hasil survei',
            'status' => 'todo',
        ]);
        $this->actingAs($mentor)->deleteJson("/api/groups/{$groupId}/members/{$intern->id}")
            ->assertOk();
        $this->assertDatabaseMissing('group_members', [
            'group_id' => $groupId,
            'user_id' => $intern->id,
        ]);

        $this->actingAs($intern)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('0.title', 'Mengolah hasil survei');
        $this->actingAs($intern)->post("/api/tasks/{$task->id}/submissions", [
            'file' => UploadedFile::fake()->createWithContent('hasil.pdf', '%PDF-1.4 hasil survei'),
        ])->assertCreated();

        $this->actingAs($mentor)->postJson("/api/groups/{$groupId}/members", [
            'user_id' => $intern->id,
        ])->assertCreated();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$intern->id, ['status' => 'rejected'])
            ->assertOk();
        $this->assertDatabaseMissing('group_members', [
            'group_id' => $groupId,
            'user_id' => $intern->id,
        ]);
    }

    public function test_task_assignment_creates_database_notification_and_report_filters_tasks(): void
    {
        Notification::fake();
        $mentor = $this->user('mentor', 'active');
        $member = $this->user('pemagang', 'active', 'Ayu Pratama');
        $otherMember = $this->user('pemagang', 'active', 'Budi Santoso');
        $group = Group::create([
            'name' => 'Tim Statistik',
            'mentor_id' => $mentor->id,
            'created_by' => $mentor->id,
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $otherMember->id]);

        $createdTask = $this->actingAs($mentor)->postJson('/api/tasks', [
            'group_id' => $group->id,
            'title' => 'Rekap survei',
            'due_date' => '2026-12-01',
            'priority' => 'high',
            'assigned_to' => $member->id,
        ])->assertCreated();

        Notification::assertSentTo($member, TaskNotification::class);
        $notificationData = (new TaskNotification(Task::findOrFail($createdTask->json('id'))))->toDatabase($member);
        $this->assertSame($group->id, $notificationData['group_id']);

        Task::create([
            'group_id' => $group->id,
            'created_by' => $mentor->id,
            'assigned_to' => $otherMember->id,
            'title' => 'Tugas anggota lain',
            'status' => 'todo',
        ]);

        $this->actingAs($member)->getJson('/api/tasks?group_id='.$group->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Rekap survei');

        $this->actingAs($member)->getJson('/api/dashboard/task-report?status=todo&priority=high')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('tasks.0.title', 'Rekap survei');

        $this->actingAs($member)->getJson('/api/dashboard/task-report?group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('summary.total', 1);

        $this->actingAs($mentor)->getJson('/api/dashboard/task-report?user_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.scheduled', 1);

        $this->actingAs($member)->getJson('/api/dashboard/task-report?user_id='.$mentor->id)
            ->assertForbidden();

        $this->actingAs($member)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonStructure(['unread_count', 'notifications']);
    }

    public function test_reading_a_notification_reduces_the_unread_count_one_at_a_time(): void
    {
        $mentor = $this->user('mentor', 'active');
        $member = $this->user('pemagang', 'active', 'Ayu Pratama');
        $group = Group::create([
            'name' => 'Tim Statistik',
            'mentor_id' => $mentor->id,
            'created_by' => $mentor->id,
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id]);
        $task = Task::create([
            'group_id' => $group->id,
            'created_by' => $mentor->id,
            'assigned_to' => $member->id,
            'title' => 'Rekap hasil',
            'status' => 'todo',
        ]);
        $member->notify(new TaskNotification($task, 'assignment', 'Tugas baru.'));
        $member->notify(new TaskNotification($task, 'submitted', 'Bukti menunggu pemeriksaan.'));

        $this->actingAs($member)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);

        $firstNotification = $member->unreadNotifications()->firstOrFail();
        $this->actingAs($member)->postJson("/api/notifications/{$firstNotification->id}/read")
            ->assertOk();
        $this->assertNotNull($firstNotification->fresh()->read_at);
        $this->actingAs($member)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $secondNotification = $member->unreadNotifications()->firstOrFail();
        $this->actingAs($member)->postJson("/api/notifications/{$secondNotification->id}/read")
            ->assertOk();
        $this->actingAs($member)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_intern_submits_private_evidence_and_manager_decides_task_completion(): void
    {
        Notification::fake();
        Storage::fake('local');
        $mentor = $this->user('mentor', 'active');
        $member = $this->user('pemagang', 'active', 'Ayu Pratama');
        $group = Group::create([
            'name' => 'Tim Statistik',
            'mentor_id' => $mentor->id,
            'created_by' => $mentor->id,
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id]);
        $task = Task::create([
            'group_id' => $group->id,
            'created_by' => $mentor->id,
            'assigned_to' => $member->id,
            'title' => 'Rekap hasil',
            'status' => 'todo',
        ]);

        $this->actingAs($member)->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])
            ->assertForbidden();

        $submission = $this->actingAs($member)->post("/api/tasks/{$task->id}/submissions", [
            'file' => UploadedFile::fake()->createWithContent('hasil.pdf', '%PDF-1.4 bukti hasil'),
            'note' => 'Hasil sudah diperiksa.',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending');

        $submissionId = $submission->json('id');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'in_review']);
        Notification::assertSentTo($mentor, TaskNotification::class);
        $this->actingAs($member)->post("/api/tasks/{$task->id}/submissions", [
            'file' => UploadedFile::fake()->createWithContent('hasil-lain.pdf', '%PDF-1.4 duplikat'),
        ])->assertUnprocessable();
        $this->actingAs($member)->get("/api/task-submissions/{$submissionId}/file")->assertOk();

        $this->actingAs($member)->patchJson("/api/task-submissions/{$submissionId}/review", [
            'status' => 'approved',
        ])->assertForbidden();

        $this->actingAs($mentor)->patchJson("/api/task-submissions/{$submissionId}/review", [
            'status' => 'approved',
        ])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'done']);
        Notification::assertSentTo($member, TaskNotification::class);
    }

    public function test_intern_can_submit_link_evidence_and_only_http_urls_are_accepted(): void
    {
        Notification::fake();
        Storage::fake('local');
        $mentor = $this->user('mentor', 'active');
        $member = $this->user('pemagang', 'active', 'Ayu Pratama');
        $group = Group::create([
            'name' => 'Tim Statistik',
            'mentor_id' => $mentor->id,
            'created_by' => $mentor->id,
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id]);
        $task = Task::create([
            'group_id' => $group->id,
            'created_by' => $mentor->id,
            'assigned_to' => $member->id,
            'title' => 'Rekap hasil',
            'status' => 'todo',
        ]);
        $endpoint = "/api/tasks/{$task->id}/submissions";

        $this->actingAs($member)->postJson($endpoint, [
            'evidence_url' => 'javascript:alert(1)',
        ])->assertUnprocessable();
        $this->actingAs($member)->postJson($endpoint, [])->assertUnprocessable();

        $url = 'https://drive.google.com/drive/folders/example';
        $submission = $this->actingAs($member)->postJson($endpoint, [
            'evidence_url' => $url,
            'note' => 'Hasil tugas tersedia di tautan.',
        ])->assertCreated()
            ->assertJsonPath('evidence_url', $url)
            ->assertJsonPath('file_name', null)
            ->assertJsonPath('download_url', null)
            ->assertJsonPath('status', 'pending');

        $submissionId = $submission->json('id');
        $this->assertDatabaseHas('task_submissions', [
            'id' => $submissionId,
            'file_path' => null,
            'evidence_url' => $url,
        ]);
        $this->actingAs($member)->get("/api/task-submissions/{$submissionId}/file")->assertNotFound();
    }

    private function user(string $role, string $status, string $name = 'Pengguna Tes'): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('user').'@example.com',
            'password' => 'password123',
            'role' => $role,
            'status' => $status,
        ]);
    }
}
