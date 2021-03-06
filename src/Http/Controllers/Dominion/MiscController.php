<?php

namespace OpenDominion\Http\Controllers\Dominion;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenDominion\Exceptions\GameException;
use OpenDominion\Factories\DominionFactory;
use OpenDominion\Http\Requests\Dominion\Actions\RestartActionRequest;
use OpenDominion\Models\Pack;
use OpenDominion\Models\Race;
use OpenDominion\Services\Dominion\ProtectionService;
use OpenDominion\Services\Dominion\TickService;

// misc functions, probably could use a refactor later
class MiscController extends AbstractDominionController
{
    public function postClearNotifications()
    {
        $this->getSelectedDominion()->notifications->markAsRead();
        return redirect()->back();
    }

    public function postClosePack()
    {
        $dominion = $this->getSelectedDominion();
        $pack = $dominion->pack;

        // Only pack creator can manually close it
        if ($pack->creator_dominion_id !== $dominion->id) {
            throw new GameException('Pack may only be closed by the creator');
        }

        $pack->closed_at = now();
        $pack->save();

        return redirect()->back();
    }

    public function postRestartDominion(RestartActionRequest $request)
    {
        $dominion = $this->getSelectedDominion();

        $dominionFactory = app(DominionFactory::class);
        $protectionService = app(ProtectionService::class);

        $this->validate($request, [
            'race' => 'required|exists:races,id',
            'dominion_name' => [
                'nullable',
                'string',
                'min:3',
                'max:50',
                'regex:/[a-zA-Z0-9]{3,}/i',
                Rule::unique('dominions', 'name')->where(function ($query) use ($dominion) {
                    return $query->where('round_id', $dominion->round_id);
                })->ignore($dominion->id)
            ],
            'ruler_name' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('dominions', 'ruler_name')->where(function ($query) use ($dominion) {
                    return $query->where('round_id', $dominion->round_id);
                })->ignore($dominion->id)
            ]
        ]);

        // Additional Race validation
        $race = Race::findOrFail($request->get('race'));
        try {
            if (!$race->playable) {
                throw new GameException('Invalid race selection');
            }

            if (!$protectionService->isUnderProtection($dominion)) {
                throw new GameException('You can only restart your dominion during protection.');
            }

            if ($dominion->realm->alignment !== 'neutral') {
                if ($dominion->realm->alignment !== $race->alignment) {
                    throw new GameException('You cannot change alignment.');
                }
            }

            if ($dominion->pack_id !== null && (int)$dominion->round->players_per_race !== 0) {
                $otherRaceId = null;

                if (((int)$dominion->round->players_per_race !== 0)) {
                    if ($race->name === 'Spirit') {
                        // Count Undead with Spirit
                        $otherRaceId = Race::where('name', 'Undead')->firstOrFail()->id;
                    } elseif ($race->name === 'Undead') {
                        // Count Spirit with Undead
                        $otherRaceId = Race::where('name', 'Spirit')->firstOrFail()->id;
                    } elseif ($race->name === 'Nomad') {
                        // Count Human with Nomad
                        $otherRaceId = Race::where('name', 'Human')->firstOrFail()->id;
                    } elseif ($race->name === 'Human') {
                        // Count Nomad with Human
                        $otherRaceId = Race::where('name', 'Nomad')->firstOrFail()->id;
                    }
                }

                $pack = Pack::where('id', $dominion->pack->id)->withCount([
                    'dominions',
                    'dominions AS players_with_race' => static function (Builder $query) use ($dominion, $race, $otherRaceId) {
                        $query->where('race_id', $race->id)->where('id', '!=', $dominion->id);
        
                        if ($otherRaceId) {
                            $query->orWhere('race_id', $otherRaceId)->where('id', '!=', $dominion->id);
                        }
                    }
                ])->first();

                if ($pack->players_with_race >= $dominion->round->players_per_race) {
                    throw new GameException('Selected race has already been chosen by the maximum amount of players.');
                }
            }
        } catch (GameException $e) {
            return redirect()->back()
                ->withInput($request->all())
                ->withErrors([$e->getMessage()]);
        }

        try {
            $dominionFactory->restart($dominion, $race, $request->get('dominion_name'), $request->get('ruler_name'));
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->back()->withErrors(['There was a problem restarting your account.']);
        }

        return redirect()->back();
    }

    public function getTickDominion(Request $request) {
        $dominion = $this->getSelectedDominion();

        $protectionService = app(ProtectionService::class);
        $tickService = app(TickService::class);

        try {
            if ($dominion->protection_ticks_remaining == 0) {
                throw new GameException('You have no protection ticks remaining.');
            }

            if ($dominion->last_tick_at > now()->subSeconds(1)) {
                throw new GameException('The Emperor is currently collecting taxes and cannot fulfill your request. Please try again.');
            }

            // Dominions still in protection or newly registered are forced
            // to wait for a short time following OOP to prevent abuse
            if ($dominion->protection_ticks_remaining == 1 && !$protectionService->canLeaveProtection($dominion)) {
                throw new GameException('You cannot leave protection during the fourth day of the round.');
            }
        } catch (GameException $e) {
            return redirect()->back()
                ->withInput($request->all())
                ->withErrors([$e->getMessage()]);
        }

        $tickService->performTick($dominion->round, $dominion);

        $dominion->protection_ticks_remaining -= 1;
        if ($dominion->protection_ticks_remaining == 48 || $dominion->protection_ticks_remaining == 24 || $dominion->protection_ticks_remaining == 0) {
            $dominion->daily_platinum = false;
            $dominion->daily_land = false;
        }
        $dominion->save();

        return redirect()->back();
    }
}
