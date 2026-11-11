<?php

namespace App\Filament\Clusters\AgentTeam\Concerns;

use App\Models\AgentDocument;
use App\Models\User;
use App\Services\Agent\Roles\AgentProfiles;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Roles\RoleRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Thêm / sửa / xóa kỹ năng tự tạo của vai trò (bảng agent_documents, kind = skill). Chỉ quản trị ghi, mỗi thay đổi ghi nhật ký (AgentProfiles).
 * Kỹ năng mặc định của hệ thống (playbook) KHÔNG xóa được: chỉ tắt/bật theo vai trò qua RoleAssignments::saveSkills.
 * Dùng chung cho trang Vai trò (khối Kỹ năng trong hồ sơ) và trang Kỹ năng.
 */
trait ManagesCustomSkills
{
    private function skillAdmin(): ?User
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isAdmin() ? $u : null;
    }

    /** @return array<string, string> khóa vai trò => tên hiển thị */
    private function roleOptions(): array
    {
        $o = [];
        foreach ((new RoleRegistry)->all() as $r) {
            $o[$r['key']] = AgentProfiles::displayName($r['key']) ?? $r['name'];
        }

        return $o;
    }

    /** @return list<Component> */
    private function skillFields(bool $creating): array
    {
        return [
            TextInput::make('title')->label('Tên kỹ năng')->required()->maxLength(160),
            TextInput::make('description')->label('Mô tả một dòng')->maxLength(200),
            Textarea::make('body')->label('Nội dung hướng dẫn (playbook)')->required()->rows(8)->maxLength(AgentProfiles::INSTRUCTIONS_MAX)
                ->helperText('Văn bản hoặc markdown. Đi vào prompt của vai trò từ lần chạy kế tiếp.'),
            $creating
                ? CheckboxList::make('roles')->label('Vai trò áp dụng')->options($this->roleOptions())->required()->columns(2)
                : Select::make('role')->label('Vai trò áp dụng')->options($this->roleOptions())->required()->native(false),
            Toggle::make('enabled')->label('Bật'),
        ];
    }

    public function createSkillAction(): Action
    {
        return Action::make('createSkill')
            ->label('Thêm kỹ năng')->icon('heroicon-m-plus')->modalHeading('Thêm kỹ năng')->modalSubmitActionLabel('Thêm')
            ->visible(fn () => $this->skillAdmin() !== null)
            ->form(fn () => $this->skillFields(true))
            ->fillForm(fn (array $arguments) => [
                'title' => $arguments['title'] ?? '', 'body' => $arguments['body'] ?? '', 'description' => '', 'enabled' => true,
                'roles' => isset($arguments['role']) ? [(string) $arguments['role']] : [],
            ])
            ->action(function (array $data, Action $action): void {
                $u = $this->skillAdmin();
                abort_unless($u !== null, 403);
                try {
                    foreach ((array) $data['roles'] as $role) {
                        AgentProfiles::addDocument((string) $role, 'skill', (string) $data['title'], (string) $data['body'], $u, $data['description'] ?? null, (bool) ($data['enabled'] ?? true));
                    }
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title('Đã thêm kỹ năng. Áp dụng từ lần chạy kế tiếp.')->success()->send();
            });
    }

    public function editSkillAction(): Action
    {
        return Action::make('editSkill')
            ->label('Sửa')->modalHeading('Sửa kỹ năng')->modalSubmitActionLabel('Lưu')
            ->visible(fn () => $this->skillAdmin() !== null)
            ->form(fn () => $this->skillFields(false))
            ->fillForm(function (array $arguments): array {
                $d = AgentDocument::query()->where('kind', 'skill')->findOrFail((int) ($arguments['id'] ?? 0));

                return ['title' => $d->title, 'description' => $d->description, 'body' => $d->body, 'role' => $d->role_key, 'enabled' => $d->enabled];
            })
            ->action(function (array $data, array $arguments, Action $action): void {
                $u = $this->skillAdmin();
                abort_unless($u !== null, 403);
                try {
                    AgentProfiles::updateSkill((int) ($arguments['id'] ?? 0), (string) $data['title'], $data['description'] ?? null, (string) $data['body'], (bool) ($data['enabled'] ?? true), (string) $data['role'], $u);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title('Đã lưu kỹ năng.')->success()->send();
            });
    }

    public function deleteSkillAction(): Action
    {
        return Action::make('deleteSkill')
            ->label('Xóa')->color('danger')->requiresConfirmation()
            ->modalHeading('Xóa kỹ năng?')->modalDescription('Kỹ năng tự tạo bị xóa hẳn và không còn đi vào prompt. Muốn giữ lại, hãy tắt thay vì xóa.')
            ->modalSubmitActionLabel('Xóa')
            ->visible(fn () => $this->skillAdmin() !== null)
            ->action(function (array $arguments): void {
                $u = $this->skillAdmin();
                abort_unless($u !== null, 403);
                $ref = (string) ($arguments['ref'] ?? '');
                if (! str_starts_with($ref, 'doc:')) {
                    Notification::make()->title('Kỹ năng mặc định của hệ thống không xóa được, chỉ tắt được.')->warning()->send();

                    return;
                }
                $id = (int) substr($ref, 4);
                if (! AgentDocument::query()->where('kind', 'skill')->whereKey($id)->exists()) {
                    return;
                }
                try {
                    AgentProfiles::deleteDocument($id, $u);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Đã xóa kỹ năng.')->success()->send();
            });
    }

    /** Bật/tắt kỹ năng tự tạo ("doc:ID") hoặc kỹ năng mặc định ("vai_tro/khoa") ở một vai trò. */
    public function toggleSkill(string $ref, bool $on, ?string $role = null): void
    {
        $u = $this->skillAdmin();
        abort_unless($u !== null, 403);
        try {
            if (str_starts_with($ref, 'doc:')) {
                AgentProfiles::toggleSkill((int) substr($ref, 4), $on, $u);

                return;
            }
            $reg = new RoleRegistry;
            $assign = new RoleAssignments($reg);
            $roles = $role !== null ? [$role] : array_column(array_filter($reg->all(), fn ($r) => in_array($ref, $r['skills'], true)), 'key');
            foreach ($roles as $rk) {
                $map = [];
                foreach ($reg->find($rk)['skills'] ?? [] as $r) {
                    $st = $assign->skillState($r);
                    $map[$r] = ['enabled' => $r === $ref ? $on : $st['enabled'], 'pin' => $st['pin']];
                }
                $assign->saveSkills($rk, $map, $u);
            }
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
