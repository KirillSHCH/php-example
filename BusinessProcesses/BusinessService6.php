<?php

namespace Modules\Indicators\Services\BusinessProcesses;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Indicators\Entities\Project;
use Modules\Indicators\Notifications\IsApprove;
use Modules\Indicators\Notifications\NotApproved;
use Modules\Indicators\Services\StatusService;

/**
 * БП6 Консорциумы
 * https://app.diagrams.net/#G11hAfuidXBYvZcijlzMHxemv3xW_Yid3p
 */
class BusinessService6 extends BusinessServiceProject
{
    protected string $edit_permission;
    protected string $approve_permission;
    protected string $notify_permission;
    protected string $reset_permission;

    public array $actual_statuses = [StatusService::APPROVED];

    public array $flow_next = [
        StatusService::DRAFT => StatusService::APPROVED,
        StatusService::NO_APPROVED => StatusService::APPROVED,
    ];

    public array $flow_prev = [
        StatusService::APPROVED => StatusService::NO_APPROVED,
    ];

    public function __construct(Project $project, User $user, string $detailed_permission)
    {
        $prefix = self::getParentModelName($project->globalProject);

        $this->edit_permission = $prefix . '-edit-' . $detailed_permission . '-consortium';
        $this->approve_permission = $prefix . '-approve-' . $detailed_permission . '-consortium';
        $this->notify_permission = $prefix . '-notify-approve-' . $detailed_permission . '-consortium';
        $this->reset_permission = $prefix . '-approval-reset-' . $detailed_permission . '-consortium';

        parent::__construct($project, $user);
    }

    public function checkAllowEdit(): bool
    {
        return $this->user->hasPermission($this->edit_permission);
    }

    public function checkAllowTrash(): bool
    {
        return $this->user->hasPermission($this->edit_permission);
    }

    public function checkAllowNext($flow, $model): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        if ($flow['next'] === StatusService::APPROVED) {
            return $this->user->hasPermission($this->approve_permission);
        }

        return false;
    }

    public function setActual($model): void
    {
        parent::setActual($model);
    }

    public function checkAllowPrev($flow, $model): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        if ($flow['current'] === StatusService::APPROVED) {
            return $this->user->hasPermission($this->reset_permission);
        }

        return false;
    }

    protected function getApprovedUserIds(): array
    {
        $result = [
            $this->globalProject->admin_id,
            $this->globalProject->curator_id,
            $this->globalProject->supervisor_id,
            $this->globalProject->team_leader_id,
            $this->project->admin_id,
            $this->project->curator_id,
            $this->project->supervisor_id,
        ];
        return array_merge(array_filter($result), $this->project->team->pluck('id')->toArray());
    }

    public function notificableNewStatus($model): void
    {
        if ($model->status === StatusService::APPROVED) {
            $users = User::byPermission($this->notify_permission)
                ->orWhereIn('id', $this->getApprovedUserIds())
                ->get();
            try {
                Notification::sendNow($users, new IsApprove($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        if ($model->status === StatusService::NO_APPROVED) {
            $users = User::byPermission($this->edit_permission)->get();
            try {
                Notification::sendNow($users, new NotApproved($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }
    }
}
