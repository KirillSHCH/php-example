<?php

namespace Modules\Indicators\Services\BusinessProcesses;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Indicators\Notifications\IsApprove;
use Modules\Indicators\Notifications\IsConfirm;
use Modules\Indicators\Notifications\NeedConfirmation;
use Modules\Indicators\Notifications\NotApproved;
use Modules\Indicators\Notifications\NotConfirmed;
use Modules\Indicators\Services\StatusService;

/**
 * БП4
 */
class BusinessService4 extends BusinessServiceSpip
{
    public array $flow_next = [
        StatusService::DRAFT => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED_ONE => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED_TWO => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED_THREE => StatusService::ON_CONFIRMATION,
        StatusService::NO_APPROVED => StatusService::ON_CONFIRMATION,

        StatusService::ON_CONFIRMATION => StatusService::CONFIRMED_ONE,
        StatusService::CONFIRMED_ONE => StatusService::CONFIRMED_TWO,
        StatusService::CONFIRMED_TWO => StatusService::APPROVED,
    ];

    public array $actual_statuses = [
        StatusService::CONFIRMED_TWO,
        StatusService::APPROVED,
    ];

    public array $flow_prev = [
        StatusService::ON_CONFIRMATION => StatusService::NO_CONFIRMED_ONE,
        StatusService::CONFIRMED_ONE => StatusService::NO_CONFIRMED_TWO,
        StatusService::CONFIRMED_TWO => StatusService::NO_APPROVED,
        StatusService::APPROVED => StatusService::NO_APPROVED,
    ];

    public function checkAllowNext($flow, $model): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        $status = $flow['next'];

        // На согласование может отправить только Администратор СПиП
        if ($status === StatusService::ON_CONFIRMATION) {
            return $this->is_spip_admin;
        }

        if ($status === StatusService::CONFIRMED_ONE) {
            return $this->is_spip_supervisor;
        }

        // Проектный офис - разрешение на согласование 2 уровня
        if ($status == StatusService::CONFIRMED_TWO) {
            $suffix = BusinessService::getSuffixByModel($model);
            return $this->user->hasPermission("sp-confirm-two-$suffix");
        }

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
            return $this->is_spip_supervisor;
        }

        if ($currentStatus === StatusService::CONFIRMED_ONE) {
            $suffix = BusinessService::getSuffixByModel($model);
            return $this->user->hasPermission("sp-confirm-two-$suffix");
        }

        if ($currentStatus === StatusService::CONFIRMED_TWO) {
            return $this->is_spip_team;
        }

        if ($currentStatus === StatusService::APPROVED) {
            return $this->is_spip_team_leader;
        }

        return false;
    }

    public function notificableNewStatus($model): void
    {
        if ($model->status === StatusService::ON_CONFIRMATION) {
            try {
                $this->globalProject->supervisor?->notifyNow(new NeedConfirmation($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        $suffix = BusinessService::getSuffixByModel($model);
        $parentModel = BusinessService::getParentModelName($model->parent);

        if ($model->status === StatusService::CONFIRMED_ONE) {
            try {
                $this->globalProject->curator?->notifyNow(new IsConfirm($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }

            // проектный офис
            $users = User::getUsersWithAccessToSPiP(
                $this->globalProject->id,
                $parentModel,
                "$parentModel-confirm-two-$suffix"
            );
            if (!empty($users)) {
                try {
                    Notification::sendNow($users, new IsConfirm($model));
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
            }
        }

        if ($model->status === StatusService::NO_CONFIRMED_ONE) {
            try {
                $this->globalProject->administrator?->notifyNow(new NotConfirmed($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        if ($model->status === StatusService::CONFIRMED_TWO) {
            // Рассылка на Бэк офис
            $users = User::getUsersWithAccessToSPiP(
                $this->globalProject->id,
                $parentModel,
                "$parentModel-notify-confirm-$suffix"
            );

            if (!empty($model->parent->team_leader)) {
                if (!$users->contains($model->parent->team_leader)) {
                    $users->add($model->parent->team_leader);
                }
            }

            if (!empty($model->parent->team)) {
                foreach ($model->parent->team as $team_part) {
                    if (!$users->contains($team_part)) {
                        $users->add($team_part);
                    }
                }
            }

            if (!empty($users)) {
                try {
                    Notification::sendNow($users, new IsConfirm($model));
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
            }
        }

        if ($model->status === StatusService::NO_CONFIRMED_TWO) {
            try {
                $this->globalProject->administrator?->notifyNow(new NotConfirmed($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }

        if ($model->status === StatusService::APPROVED) {
            // Рассылка на Управляющий совет
            $users = User::byPermission("sp-notify-approve-$suffix")->get();
            if (!empty($users)) {
                try {
                    Notification::sendNow($users, new IsApprove($model));
                } catch (\Exception $exception) {
                    Log::error($exception);
                }
            }
        }

        if ($model->status === StatusService::NO_APPROVED) {
            try {
                $this->globalProject->administrator?->notifyNow(new NotApproved($model));
            } catch (\Exception $exception) {
                Log::error($exception);
            }
        }
    }
}
