<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Models\Campaign;
use Kirora\Models\CampaignEligibility;
use Kirora\Models\CampaignListItem;
use Kirora\Models\TriggeredCampaign;
use Kirora\Page;

/**
 * Messages sent once to an audience, by email, push or both.
 */
class Campaigns extends Service
{
    /**
     * Fetches a page of campaigns (listCampaigns). See `all()` for every campaign.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Page<CampaignListItem>
     */
    public function list(array $query = [], array $options = []): Page
    {
        return $this->page(CampaignListItem::class, '/v1/campaigns', $query, $options);
    }

    /**
     * Iterates over every campaign, fetching pages as it goes (listCampaigns).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, CampaignListItem>
     */
    public function all(array $query = [], array $options = []): Generator
    {
        return $this->every(CampaignListItem::class, '/v1/campaigns', $query, $options);
    }

    /**
     * Fetches a campaign (getCampaign).
     *
     * @param  array<string, mixed>  $options
     */
    public function get(string $campaignId, array $options = []): Campaign
    {
        return $this->one(Campaign::class, 'GET', self::path('/v1/campaigns/%s', $campaignId), [], null, $options);
    }

    /**
     * Creates a draft campaign (createCampaign). Retries reuse one idempotency key, so they
     * never create two.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): Campaign
    {
        return $this->one(Campaign::class, 'POST', '/v1/campaigns', [], $params, $options);
    }

    /**
     * Sends a campaign now (sendCampaign). A campaign is only ever sent once, so `send()` and
     * `schedule()` are alternatives. A key is made for the call, so a retry replays the result
     * rather than failing once the send has started.
     *
     * @param  array<string, mixed>  $options
     */
    public function send(string $campaignId, array $options = []): Campaign
    {
        return $this->one(Campaign::class, 'POST', self::path('/v1/campaigns/%s/send', $campaignId), [], null, $options);
    }

    /**
     * Sends a campaign to the given contacts, each with its own `data`, instead of its saved
     * audience (triggerCampaign).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function trigger(string $campaignId, array $params, array $options = []): TriggeredCampaign
    {
        return $this->one(TriggeredCampaign::class, 'POST', self::path('/v1/campaigns/%s/trigger', $campaignId), [], $params, $options);
    }

    /**
     * Schedules a draft campaign, or reschedules one that hasn't started sending
     * (scheduleCampaign). Type `fixed` sends at `sendAt` for everyone; `local_time` at
     * `localDateTime` on each contact's own clock.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function schedule(string $campaignId, array $params, array $options = []): Campaign
    {
        return $this->one(Campaign::class, 'POST', self::path('/v1/campaigns/%s/schedule', $campaignId), [], $params, $options);
    }

    /**
     * Lists anything that would block sending a campaign, without sending it
     * (getCampaignEligibility).
     *
     * @param  array<string, mixed>  $options
     */
    public function eligibility(string $campaignId, array $options = []): CampaignEligibility
    {
        return $this->one(CampaignEligibility::class, 'GET', self::path('/v1/campaigns/%s/eligibility', $campaignId), [], null, $options);
    }
}
