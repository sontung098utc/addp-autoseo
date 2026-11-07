<?php

namespace App\Livewire;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Support\Agent\RunActivity;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** AG-S07: pill toàn cục "Đội AI đang chạy (N)"; chỉ hiện cho người duyệt/admin và chỉ khi có phiên running thật. */
class AgentRunningPill extends Component
{
    public function render()
    {
        $u = Auth::user();
        $show = $u instanceof \App\Models\User && $u->is_active && $u->isApprover();

        return view('livewire.agent-running-pill', [
            'n' => $show ? RunActivity::liveCount() : 0,
            'url' => $show ? AgentRunResource::getUrl('index') : '#',
        ]);
    }
}
