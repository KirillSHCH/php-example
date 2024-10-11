<?php

namespace Modules\Indicators\Services\BusinessProcesses;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Indicators\Notifications\IsApprove;
use Modules\Indicators\Notifications\NeedApproval;
use Modules\Indicators\Notifications\NotApproved;
use Modules\Indicators\Services\StatusService;

/**
 * БП1
 */
class BusinessService1 extends BusinessService
{
    public bool $user_can_edit;
    private string $detailed_permission;

    public function __construct(User $user, string $detailed_permission)
    {
        parent::__construct($user);

        $this->detailed_permission = $detailed_permission;
        $this->user_can_edit = ($this->user->hasDetail(
                'program-edit',
                $this->detailed_permission
            ) || $this->user->isAdmin());
    }

    public array $actual_statuses = [
        StatusService::APPROVED,
        StatusService::ON_APPROVAL,
    ];

    public array $flow_next = [
        StatusService::DRAFT => StatusService::ON_APPROVAL,
        StatusService::NO_APPROVED => StatusService::ON_APPROVAL,

        StatusService::ON_APPROVAL => StatusService::APPROVED,
    ];

    public array $flow_prev = [
        StatusService::ON_APPROVAL => StatusService::NO_APPROVED,
        StatusService::APPROVED => StatusService::NO_APPROVED,
    ];

    public function applyFlowsPermissions($flows, $model): array
    {
        // Редактировать может только пользователь с ролью, в которой есть разрешение ввода/редактирования данных
        if ($flows['edit']) {
            $flows['edit'] = $this->user_can_edit;
        }

        // Переход по статусам
        if (($flows['next'] !== false) && (!$this->checkAllowNext($flows, $model))) {
            $flows['next'] = false;
        }

        if (($flows['prev'] !== false) && (!$this->checkAllowPrev($flows, $model))) {
            $flows['prev'] = false;
        }

        if ($flows['trash']) {
            $flows['trash'] = $this->user_can_edit;
        }

        return $flows;
    }

    public function checkAllowNext($flow, $model): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        $status = $flow['next'];

        // Отправить на утверждение может проектный офис
        if ($status === StatusService::ON_APPROVAL) {
            return $this->user->hasDetail('program-edit', $this->detailed_permission);
        }

        // Утвердить может только Управляющий совет
        if ($status === StatusService::APPROVED) {
            return $this->user->hasDetail('program-approval', $this->detailed_permission);
        }

        return false;
    }

    public function checkAllowPrev($flow, $model): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        $currentStatus = $flow['current'];

        // На согласование может отправить только Администратор СПиП
        if ($currentStatus === StatusService::ON_APPROVAL) {
            return $this->user->hasDetail('program-approval', $this->detailed_permission);
        }

        if ($currentStatus === StatusService::APPROVED) {
            return $this->user->hasDetail('program-approval-reset', $this->detailed_permission);
        }

        return false;
    }

    public function notificableNewStatus($model): void
    {
        if ($model->status === StatusService::ON_APPROVAL) {
            $users = $this->getUsersByPermissionWithDetails('program-approval', $this->detailed_permission);

            try {
                Notification::sendNow($users, new NeedApproval($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        if ($model->status === StatusService::APPROVED) {
            // Рассылка на Управляющий совет
            $users = $this->getUsersByPermissionWithDetails('program-notify-approved', $this->detailed_permission);

            try {
                Notification::sendNow($users, new IsApprove($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        if ($model->status === StatusService::NO_APPROVED) {
            $users = $this->getUsersByPermissionWithDetails('program-edit', $this->detailed_permission);

            try {
                Notification::sendNow($users, new NotApproved($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }
    }
}
