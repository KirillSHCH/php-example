<?php

namespace Modules\Indicators\Services\BusinessProcesses;

use App\Models\User;
use Modules\Indicators\Entities\Project;

abstract class BusinessServiceProject extends BusinessServiceSpip
{
    public bool $is_project_admin = false;
    public bool $is_project_curator = false;
    public bool $is_project_supervisor = false;
    public bool $is_project_team = false;
    public bool $user_can_edit = false;

    protected Project $project;

    public function __construct(Project $project, User $user)
    {
        parent::__construct($project->globalProject, $user);

        $this->project = $project;

        $this->is_project_admin = ($this->project->admin_id === $this->user->id);
        $this->is_project_curator = ($this->project->curator_id === $this->user->id);
        $this->is_project_supervisor = ($this->project->supervisor_id === $this->user->id);

        $this->is_project_team = $this->project->team?->contains($this->user);

        $this->user_can_edit = $this->checkAllowEdit();
    }

    public function checkAllowEdit(): bool
    {
        return parent::checkAllowEdit() || $this->is_project_admin;
    }

    public function checkAllowTrash(): bool
    {
        return parent::checkAllowTrash() || $this->is_project_admin;
    }
}
