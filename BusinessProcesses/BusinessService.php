<?php

namespace Modules\Indicators\Services\BusinessProcesses;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Indicators\Entities\GlobalProject;
use Modules\Indicators\Services\StatusService;

/**
 *  Абстрактный класс, от которого наследуются остальные
 *  (сделал отдельно BusinessService и BusinessServiceSpip, т.к. БП1 используется в программе развития, а там нет GlobalProject)
 */
abstract class BusinessService
{
    public array $actual_statuses = [
        StatusService::CONFIRMED,
        StatusService::APPROVED,
    ];

    public array $back_statuses = [
        StatusService::NO_CONFIRMED,
        StatusService::NO_CONFIRMED_ONE,
        StatusService::NO_CONFIRMED_TWO,
        StatusService::NO_CONFIRMED_THREE,
        StatusService::NO_APPROVED,
    ];

    public array $flow_next = [
        StatusService::DRAFT => StatusService::ON_CONFIRMATION,
        StatusService::NO_CONFIRMED => StatusService::ON_CONFIRMATION,
        StatusService::NO_APPROVED => StatusService::ON_CONFIRMATION,

        StatusService::ON_CONFIRMATION => StatusService::CONFIRMED,
        StatusService::CONFIRMED => StatusService::ON_APPROVAL,
        StatusService::ON_APPROVAL => StatusService::APPROVED,
    ];

    public array $flow_prev = [
        StatusService::ON_CONFIRMATION => StatusService::NO_CONFIRMED,
        StatusService::CONFIRMED => StatusService::NO_CONFIRMED,
        StatusService::ON_APPROVAL => StatusService::NO_APPROVED,
        StatusService::APPROVED => StatusService::NO_APPROVED,
    ];

    public array $flow_edit = [
        StatusService::DRAFT,
        StatusService::NO_CONFIRMED,
        StatusService::NO_CONFIRMED_ONE,
        StatusService::NO_CONFIRMED_TWO,
        StatusService::NO_CONFIRMED_THREE,
        StatusService::NO_APPROVED,
    ];

    public array $flow_trash = [
        StatusService::DRAFT,
        StatusService::NO_CONFIRMED,
        StatusService::NO_CONFIRMED_ONE,
        StatusService::NO_CONFIRMED_TWO,
        StatusService::NO_CONFIRMED_THREE,
        StatusService::NO_APPROVED,
    ];

    public array $flow_auto = [
        StatusService::CONFIRMED => StatusService::ON_APPROVAL,
    ];

    protected User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    abstract public function applyFlowsPermissions($flows, $model): array;

    abstract public function checkAllowNext($flow, $model): bool;

    abstract public function checkAllowPrev($flow, $model): bool;

    abstract public function notificableNewStatus($model): void;

    public function getAllowFlows($model): array
    {
        $status = optional($model)->status;

        $result = [];

        $result['current'] = $status;

        $result['next'] = $this->flow_next[$status] ?? false;

        $result['prev'] = $this->flow_prev[$status] ?? false;

        $result['edit'] = in_array($status, $this->flow_edit);

        $result['trash'] = in_array($status, $this->flow_trash);

        return $result;
    }

    /**
     * Бизнес процесс при применение нового статуса
     * @param $model
     * @return void
     */
    public function applyNewStatus($model): void
    {
        // Установить флаг is_actual (для дашборда) в соотвествии с БЛ
        $this->setActual($model);

        // Отправить необходимые уведомления
        $this->notificableNewStatus($model);

        // Автоматические статусы
        $this->applyAutoFlow($model);
    }

    protected function setActual($model): void
    {
        $model->resetActual();

        if (in_array($model->status, $this->actual_statuses)) {
            $model->update(['is_actual' => true]);
        }
//        else if (!in_array($model->status, $this->back_statuses)) {
//            $actual_model = $model->where('parent_id', $model->parent_id)
//                ->where('parent_type', $model->parent_type)
//                ->where(function ($builder) {
//                    $builder->whereIn('status', $this->actual_statuses);
//                })
//                ->orderBy('id', 'desc')
//                ->first();
//
//            $actual_model?->update(['is_actual' => true]);
//        }
    }

    /**
     * Автоматический перевод статусов
     * @param $model
     * @return void
     */
    public function applyAutoFlow($model): void
    {
        if (isset($this->flow_auto[$model->status])) {
            $new = $model::newVersion($model, ['status' => $this->flow_auto[$model->status]]);
            $new->save();
            $this->applyNewStatus($model);
        }
    }

    public function actions($model): array
    {
        $flows = $this->getAllowFlows($model);

        $flows = $this->applyFlowsPermissions($flows, $model);

        return $flows;
    }

    public static function emptyActions(): array
    {
        return [
            'current' => 0,
            'next' => false,
            'prev' => false,
            'edit' => true,
        ];
    }

    public static function getSuffixByModel($model): string
    {
        return Str::lower(class_basename($model));
    }

    public static function getParentModelName($model): string
    {
        if ($model->type === GlobalProject::STRATEGIC_PROJECT) {
            return 'sp';
        } else {
            return 'politic';
        }
    }

    public static function getUsersByPermissionWithDetails(string $asked_permission, $detailed_permission): Collection
    {
        $users = User::byPermission($asked_permission)->get();
        $result = collect();

        foreach ($users as $user) {
            if ($user->hasDetail($asked_permission, $detailed_permission)) {
                if (!$result->contains($user)) {
                    $result->add($user);
                }
            }
        }

        return $result;
    }
}
