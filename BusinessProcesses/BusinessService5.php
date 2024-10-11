<?php

namespace Modules\Indicators\Services\BusinessProcesses;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Indicators\Entities\Project;
use Modules\Indicators\Notifications\IsApprove;
use Modules\Indicators\Notifications\IsConfirm;
use Modules\Indicators\Notifications\NeedApproval;
use Modules\Indicators\Notifications\NeedConfirmation;
use Modules\Indicators\Notifications\NotApproved;
use Modules\Indicators\Notifications\NotConfirmed;
use Modules\Indicators\Services\StatusService;

class BusinessService5 extends BusinessServiceProject
{
    public string $model_type;

    public function __construct(Project $project, User $user, string $model_type)
    {
        parent::__construct($project, $user);
        $this->model_type = $model_type;
    }

    public array $flow_next = [
        StatusService::DRAFT => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED_ONE => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED_TWO => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED_THREE => StatusService::ON_CONFIRMATION,
        StatusService::NO_APPROVED => StatusService::ON_CONFIRMATION,

        StatusService::ON_CONFIRMATION => StatusService::CONFIRMED_ONE,
        StatusService::CONFIRMED_ONE => StatusService::CONFIRMED_TWO,
        StatusService::CONFIRMED_TWO => StatusService::CONFIRMED_THREE,
        StatusService::CONFIRMED_THREE => StatusService::APPROVED,
    ];

    public array $flow_prev = [
        StatusService::ON_CONFIRMATION => StatusService::NO_CONFIRMED_ONE,
        StatusService::CONFIRMED_ONE => StatusService::NO_CONFIRMED_TWO,
        StatusService::CONFIRMED_TWO => StatusService::NO_CONFIRMED_THREE,
        StatusService::CONFIRMED_THREE => StatusService::NO_APPROVED,
        StatusService::APPROVED => StatusService::NO_APPROVED,
    ];

    public array $flow_edit = [
        StatusService::DRAFT,
        StatusService::NO_CONFIRMED_ONE,
        StatusService::NO_CONFIRMED_TWO,
        StatusService::NO_CONFIRMED_THREE,
        StatusService::NO_APPROVED,
    ];

    public array $actual_statuses = [
        StatusService::APPROVED,
        StatusService::CONFIRMED_THREE,
    ];

    public function actions($model): array
    {
        $actions = parent::actions($model);

        // Доступ для редактировние - для возможности удалить документы
        if (($actions['current'] == StatusService::CONFIRMED_TWO) && ($this->model_type === 'event-go-exec')) {
            $actions['edit'] = $this->user->hasPermission('delete-confirm-file') || $this->user->isAdmin();
        }

        return $actions;
    }

    public function checkAllowNext($flow, $model): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        $status = $flow['next'];

        // На согласование может отправить только Администратор Проекта или СПиП
        if ($status === StatusService::ON_CONFIRMATION) {
            return $this->is_spip_admin || $this->is_project_admin;
        }

        // Согласовать ур.1 может только руководитель проекта
        if ($status === StatusService::CONFIRMED_ONE) {
            return $this->is_project_supervisor;
        }

        // Согласовать ур.2 может только руководитель глобального проекта
        if ($status === StatusService::CONFIRMED_TWO) {
            return $this->is_spip_supervisor;
        }

        // Проектный офис или Бэк офис - разрешение на согласование 3 уровня
        if ($status == StatusService::CONFIRMED_THREE) {
            $parent_type = BusinessService::getParentModelName($this->globalProject);

            // Т.к. в правах допустили опечатку, и права на согласование связанных мероприятий и инструментов начинаются на: event-...
            // а права на уведомления: fo-...
            if ($this->model_type === 'fo-link') {
                $permission = $parent_type . '-confirm-three-project-event-link';
            } else {
                if ($this->model_type === 'fo-instrument') {
                    $permission = $parent_type . '-confirm-three-project-event-instrument';
                } else {
                    $permission = $parent_type . '-confirm-three-project-' . $this->model_type;
                }
            }

            return $this->user->hasPermission($permission);
        }

        // Утвердить может проектный коммитет
        if ($status === StatusService::APPROVED) {
            return $this->is_spip_team;
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
        if ($currentStatus === StatusService::ON_CONFIRMATION) {
            return $this->is_project_supervisor;
        }

        if ($currentStatus === StatusService::CONFIRMED_ONE) {
            return $this->is_spip_supervisor;
        }

        if ($currentStatus === StatusService::CONFIRMED_TWO) {
            $parent_type = BusinessService::getParentModelName($this->project->globalProject);

            // Т.к. в правах допустили опечатку, и права на согласование связанных мероприятий и инструментов начинаются на: event-...
            // а права на уведомления: fo-...
            if ($this->model_type === 'fo-link') {
                $permission = $parent_type . '-confirm-three-project-event-link';
            } else {
                if ($this->model_type === 'fo-instrument') {
                    $permission = $parent_type . '-confirm-three-project-event-instrument';
                } else {
                    $permission = $parent_type . '-confirm-three-project-' . $this->model_type;
                }
            }

            return $this->user->hasPermission($permission);
        }

        if (in_array($currentStatus, [StatusService::CONFIRMED_THREE, StatusService::APPROVED])) {
            return $this->is_spip_team_leader;
        }

        return false;
    }

    public function notificableNewStatus($model): void
    {
        if ($model->status === StatusService::ON_CONFIRMATION) {
            try {
                $this->project->supervisor?->notifyNow(new NeedConfirmation($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        if ($model->status === StatusService::CONFIRMED_ONE) {
            try {
                $this->project->curator?->notifyNow(new IsConfirm($model));
                // проектный офис
                $this->globalProject->supervisor?->notifyNow(new IsConfirm($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        $parent_type = self::getParentModelName($this->globalProject);

        if ($model->status === StatusService::CONFIRMED_TWO) {
            try {
                // Рассылка на Бэк офис
                $this->globalProject->curator?->notifyNow(new IsConfirm($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }

            // Т.к. в правах допустили опечатку, и права на согласование связанных мероприятий и инструментов начинаются на: event-...
            // а права на уведомления: fo-...
            if ($this->model_type === 'fo-link') {
                $permission = $parent_type . '-confirm-three-project-event-link';
            } else {
                if ($this->model_type === 'fo-instrument') {
                    $permission = $parent_type . '-confirm-three-project-event-instrument';
                } else {
                    $permission = $parent_type . '-confirm-three-project-' . $this->model_type;
                }
            }

            $users = User::byPermission($permission)->get();
            if (!empty($users)) {
                try {
                    Notification::sendNow($users, new IsConfirm($model));
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
            }
        }

        if ($model->status === StatusService::CONFIRMED_THREE) {
            $spip_team = collect();

            if ($this->globalProject->team) {
                $spip_team = $this->globalProject->team;
            }

            if ($this->globalProject->team_leader && !$spip_team->contains($this->globalProject->team_leader)) {
                $spip_team = $spip_team->add($this->globalProject->team_leader);
            }

            if (!empty($spip_team)) {
                try {
                    Notification::sendNow($spip_team, new NeedApproval($model));
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
            }

            $permission = $parent_type . '-notify-confirm-three-project-' . $this->model_type;
            $users = User::byPermission($permission)->get();
            if (!empty($users)) {
                try {
                    Notification::sendNow($users, new IsConfirm($model));
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
            }
        }

        if ($model->status === StatusService::APPROVED) {
            // Рассылка на Управляющий совет
            $permission = $parent_type . '-notify-approve-project-' . $this->model_type;
            $users = User::byPermission($permission)->get();
            if (!empty($users)) {
                try {
                    Notification::sendNow($users, new IsApprove($model));
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
            }
        }

        //  Оповещения по отправке формы назад
        $status_back_model = $model->query()
            ->where('parent_id', $model->parent_id)
            ->where('status', StatusService::DRAFT)
            ->first();

        $status_back_user = User::where('id', $status_back_model?->editor_id)->first();
        $status_back_users = collect();

        if (!empty($this->globalProject->administrator)) {
            $status_back_users->add($this->globalProject->administrator);
        }

        if (!empty($this->project->administrator)) {
            $status_back_users->add($this->project->administrator);
        }

        if (in_array(
            $model->status,
            [StatusService::NO_CONFIRMED_ONE, StatusService::NO_CONFIRMED_TWO, StatusService::NO_CONFIRMED_THREE]
        )) {
            try {
                $status_back_user
                    ? $status_back_user->notifyNow(new NotConfirmed($model))
                    : Notification::sendNow($status_back_users, new NotConfirmed($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        if ($model->status === StatusService::NO_APPROVED) {
            try {
                $status_back_user
                    ? $status_back_user->notifyNow(new NotApproved($model))
                    : Notification::sendNow($status_back_users, new NotApproved($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }
    }
}
