<?php

namespace Modules\Indicators\Services\BusinessProcesses;

use App\Models\User;
use Modules\Indicators\Entities\GlobalProject;

/**
 * BusinessService для наследования в те БП, в которых присутствует  GlobalProject
 */
class BusinessServiceSpip extends BusinessService
{
    public bool $is_spip_admin = false;
    public bool $is_spip_curator = false;
    public bool $is_spip_supervisor = false;
    public bool $is_spip_team = false;
    public bool $is_spip_team_leader = false;

    protected GlobalProject $globalProject;

    public function __construct(GlobalProject $globalProject, User $user)
    {
        parent::__construct($user);

        $this->globalProject = $globalProject;

        $this->is_spip_admin = ($this->globalProject->admin_id === $this->user->id);
        $this->is_spip_curator = ($this->globalProject->curator_id === $this->user->id);
        $this->is_spip_supervisor = ($this->globalProject->supervisor_id === $this->user->id);

        $this->is_spip_team_leader = ($this->globalProject->team_leader_id === $this->user->id);
        $this->is_spip_team = $this->is_spip_team_leader || ($this->globalProject->team?->contains($this->user));
    }

    public function applyFlowsPermissions($flows, $model): array
    {
        // Редактировать может только Админисиратор СПИП
        if ($flows['edit']) {
            $flows['edit'] = $this->checkAllowEdit();
        }

        // Переход по статусам
        if (($flows['next'] !== false) && (!$this->checkAllowNext($flows, $model))) {
            $flows['next'] = false;
        }

        if (($flows['prev'] !== false) && (!$this->checkAllowPrev($flows, $model))) {
            $flows['prev'] = false;
        }

        if ($flows['trash']) {
            $flows['trash'] = $this->checkAllowTrash();
        }

        return $flows;
    }

    public function checkAllowEdit(): bool
    {
        return $this->is_spip_admin || $this->user->isAdmin();
    }

    public function checkAllowTrash(): bool
    {
        return $this->is_spip_admin || $this->user->isAdmin();
    }

    public function checkAllowNext($flow, $model): bool
    {
        return true;
    }

    public function checkAllowPrev($flow, $model): bool
    {
        return true;
    }

    public function notificableNewStatus($model): void
    {
        // TODO: Implement notificableNewStatus() method.
    }
}
