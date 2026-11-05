<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kirora\Client;
use Kirora\Models;
use Kirora\Operations;
use Kirora\Page;

/**
 * Every service method: what it sends, and what it returns from the API's response.
 *
 * @return array<string, array{0: Closure(Client): mixed, 1: string, 2: string, 3: string}>
 */
function contract(): array
{
    $p = ['a' => 1];

    return [
        'contacts.list' => [fn (Client $k) => $k->contacts->list(), 'GET', '/v1/contacts', 'page:' . Models\ContactDetail::class],
        'contacts.get' => [fn (Client $k) => $k->contacts->get('c1'), 'GET', '/v1/contacts/c1', Models\ContactDetail::class],
        'contacts.update' => [fn (Client $k) => $k->contacts->update('c1', $p), 'PATCH', '/v1/contacts/c1', Models\ContactDetail::class],
        'contacts.delete' => [fn (Client $k) => $k->contacts->delete('c1'), 'DELETE', '/v1/contacts/c1', 'none'],
        'contacts.identify' => [fn (Client $k) => $k->contacts->identify($p), 'POST', '/v1/contacts/identify', Models\ContactDetail::class],
        'contacts.identifyBatch' => [fn (Client $k) => $k->contacts->identifyBatch($p), 'POST', '/v1/contacts/batch', Models\IdentifyContactsBatchResult::class],
        'contacts.deleteBatch' => [fn (Client $k) => $k->contacts->deleteBatch($p), 'POST', '/v1/contacts/delete', Models\DeleteContactsResult::class],
        'contacts.merge' => [fn (Client $k) => $k->contacts->merge('c1', $p), 'POST', '/v1/contacts/c1/merge', Models\ContactDetail::class],
        'contacts.export' => [fn (Client $k) => $k->contacts->export('c1'), 'GET', '/v1/contacts/c1/export', Models\ContactDataExport::class],
        'contacts.lists.list' => [fn (Client $k) => $k->contacts->lists->list('c1'), 'GET', '/v1/contacts/c1/lists', 'page:' . Models\ContactListMembership::class],
        'contacts.topics.list' => [fn (Client $k) => $k->contacts->topics->list('c1'), 'GET', '/v1/contacts/c1/topics', 'many:' . Models\ContactTopic::class],
        'contacts.topics.set' => [fn (Client $k) => $k->contacts->topics->set('c1', 't1', $p), 'PUT', '/v1/contacts/c1/topics/t1', Models\ContactTopic::class],
        'contacts.installations.list' => [fn (Client $k) => $k->contacts->installations->list('c1'), 'GET', '/v1/contacts/c1/installations', 'page:' . Models\PushInstallation::class],
        'contacts.installations.register' => [fn (Client $k) => $k->contacts->installations->register('c1', $p), 'POST', '/v1/contacts/c1/installations', Models\PushInstallation::class],
        'contacts.installations.delete' => [fn (Client $k) => $k->contacts->installations->delete('c1', 'i1'), 'DELETE', '/v1/contacts/c1/installations/i1', 'none'],
        'events.track' => [fn (Client $k) => $k->events->track($p), 'POST', '/v1/events/track', Models\TrackedEvent::class],
        'events.trackBatch' => [fn (Client $k) => $k->events->trackBatch($p), 'POST', '/v1/events/batch', Models\TrackEventsBatchResult::class],
        'transactional.send' => [fn (Client $k) => $k->transactional->send($p), 'POST', '/v1/transactional/messages', Models\TransactionalSendResult::class],
        'transactional.sendBatch' => [fn (Client $k) => $k->transactional->sendBatch($p), 'POST', '/v1/transactional/messages/batch', Models\SendTransactionalMessagesBatchResult::class],
        'transactional.cancel' => [fn (Client $k) => $k->transactional->cancel('m1'), 'POST', '/v1/transactional/messages/m1/cancel', Models\OutboundMessage::class],
        'campaigns.list' => [fn (Client $k) => $k->campaigns->list(), 'GET', '/v1/campaigns', 'page:' . Models\CampaignListItem::class],
        'campaigns.get' => [fn (Client $k) => $k->campaigns->get('x1'), 'GET', '/v1/campaigns/x1', Models\Campaign::class],
        'campaigns.create' => [fn (Client $k) => $k->campaigns->create($p), 'POST', '/v1/campaigns', Models\Campaign::class],
        'campaigns.send' => [fn (Client $k) => $k->campaigns->send('x1'), 'POST', '/v1/campaigns/x1/send', Models\Campaign::class],
        'campaigns.trigger' => [fn (Client $k) => $k->campaigns->trigger('x1', $p), 'POST', '/v1/campaigns/x1/trigger', Models\TriggeredCampaign::class],
        'campaigns.schedule' => [fn (Client $k) => $k->campaigns->schedule('x1', $p), 'POST', '/v1/campaigns/x1/schedule', Models\Campaign::class],
        'campaigns.eligibility' => [fn (Client $k) => $k->campaigns->eligibility('x1'), 'GET', '/v1/campaigns/x1/eligibility', Models\CampaignEligibility::class],
        'lists.list' => [fn (Client $k) => $k->lists->list(), 'GET', '/v1/lists', 'page:' . Models\ListModel::class],
        'lists.get' => [fn (Client $k) => $k->lists->get('l1'), 'GET', '/v1/lists/l1', Models\ListModel::class],
        'lists.create' => [fn (Client $k) => $k->lists->create($p), 'POST', '/v1/lists', Models\ListModel::class],
        'lists.update' => [fn (Client $k) => $k->lists->update('l1', $p), 'PATCH', '/v1/lists/l1', Models\ListModel::class],
        'lists.delete' => [fn (Client $k) => $k->lists->delete('l1'), 'DELETE', '/v1/lists/l1', 'none'],
        'lists.addContacts' => [fn (Client $k) => $k->lists->addContacts('l1', $p), 'POST', '/v1/lists/l1/contacts', Models\AddedListContacts::class],
        'lists.deleteContacts' => [fn (Client $k) => $k->lists->deleteContacts('l1', $p), 'POST', '/v1/lists/l1/contacts/delete', Models\DeletedListContacts::class],
        'messages.list' => [fn (Client $k) => $k->messages->list(), 'GET', '/v1/messages', 'page:' . Models\OutboundMessage::class],
        'messages.get' => [fn (Client $k) => $k->messages->get('m1'), 'GET', '/v1/messages/m1', Models\OutboundMessage::class],
        'suppressions.list' => [fn (Client $k) => $k->suppressions->list(), 'GET', '/v1/suppressions', 'page:' . Models\Suppression::class],
        'suppressions.add' => [fn (Client $k) => $k->suppressions->add($p), 'POST', '/v1/suppressions', Models\AddedSuppressions::class],
        'suppressions.delete' => [fn (Client $k) => $k->suppressions->delete($p), 'POST', '/v1/suppressions/delete', Models\DeletedSuppressions::class],
        'workspaces.list' => [fn (Client $k) => $k->workspaces->list(), 'GET', '/v1/workspaces', 'page:' . Models\Workspace::class],
        'workspaces.create' => [fn (Client $k) => $k->workspaces->create($p), 'POST', '/v1/workspaces', Models\Workspace::class],
        'workspaces.get' => [fn (Client $k) => $k->workspaces->get('w1'), 'GET', '/v1/workspaces/w1', Models\Workspace::class],
        'workspaces.update' => [fn (Client $k) => $k->workspaces->update('w1', $p), 'PATCH', '/v1/workspaces/w1', Models\Workspace::class],
        'workspaces.delete' => [fn (Client $k) => $k->workspaces->delete('w1'), 'DELETE', '/v1/workspaces/w1', Models\Workspace::class],
        'workspaces.suspend' => [fn (Client $k) => $k->workspaces->suspend('w1'), 'POST', '/v1/workspaces/w1/suspend', Models\Workspace::class],
        'workspaces.resume' => [fn (Client $k) => $k->workspaces->resume('w1'), 'POST', '/v1/workspaces/w1/resume', Models\Workspace::class],
        'workspaces.cancelDeletion' => [fn (Client $k) => $k->workspaces->cancelDeletion('w1'), 'POST', '/v1/workspaces/w1/cancel-deletion', Models\Workspace::class],
        'embedSessions.create' => [fn (Client $k) => $k->embedSessions->create($p), 'POST', '/v1/embed-sessions', Models\EmbedSession::class],
        'me' => [fn (Client $k) => $k->me(), 'GET', '/v1/me', Models\ApiCaller::class],
        'contacts.segments.list' => [fn (Client $k) => $k->contacts->segments->list('c1'), 'GET', '/v1/contacts/c1/segments', 'page:' . Models\ContactSegment::class],
        'segments.list' => [fn (Client $k) => $k->segments->list(), 'GET', '/v1/segments', 'page:' . Models\Segment::class],
        'segments.create' => [fn (Client $k) => $k->segments->create($p), 'POST', '/v1/segments', Models\Segment::class],
        'segments.preview' => [fn (Client $k) => $k->segments->preview($p), 'POST', '/v1/segments/preview', Models\SegmentPreviewResult::class],
        'segments.get' => [fn (Client $k) => $k->segments->get('s1'), 'GET', '/v1/segments/s1', Models\Segment::class],
        'segments.update' => [fn (Client $k) => $k->segments->update('s1', $p), 'PATCH', '/v1/segments/s1', Models\Segment::class],
        'segments.delete' => [fn (Client $k) => $k->segments->delete('s1'), 'DELETE', '/v1/segments/s1', 'none'],
        'segments.listContacts' => [fn (Client $k) => $k->segments->listContacts('s1'), 'GET', '/v1/segments/s1/contacts', 'page:' . Models\ContactDetail::class],
        'segments.listMembershipChanges' => [fn (Client $k) => $k->segments->listMembershipChanges('s1', ['change' => 'joined', 'day' => '2026-11-01']), 'GET', '/v1/segments/s1/membership-changes', 'page:' . Models\ContactDetail::class],
        'segments.sizeHistory' => [fn (Client $k) => $k->segments->sizeHistory('s1'), 'GET', '/v1/segments/s1/size-history', Models\SegmentSizeHistory::class],
        'segments.usage' => [fn (Client $k) => $k->segments->usage('s1'), 'GET', '/v1/segments/s1/usage', Models\SegmentUsage::class],
    ];
}

it('calls the endpoint and returns its model', function (Closure $call, string $method, string $path, string $returns) {
    $response = match (true) {
        $returns === 'none' => new Response(204),
        str_starts_with($returns, 'page:') => page([['id' => 'x']]),
        str_starts_with($returns, 'many:') => data([['id' => 'x']]),
        default => data(['id' => 'x']),
    };
    $api = fakeApi([$response]);
    $result = $call($api->client);

    expect($api->request()->getMethod())->toBe($method)
        ->and($api->request()->getUri()->getPath())->toBe($path);
    if ($returns === 'none') {
        expect($result)->toBeNull();
    } elseif (str_starts_with($returns, 'page:')) {
        expect($result)->toBeInstanceOf(Page::class)->and($result->data[0])->toBeInstanceOf(substr($returns, 5));
    } elseif (str_starts_with($returns, 'many:')) {
        expect($result)->toBeArray()->and($result[0])->toBeInstanceOf(substr($returns, 5));
    } else {
        expect($result)->toBeInstanceOf($returns)->and($result->id)->toBe('x');
    }
    if (in_array($method, ['POST', 'PATCH', 'PUT'], true) && $api->body() !== null) {
        expect($api->body())->toBe(['a' => 1]);
    }
})->with(contract());

it('covers every operation the codegen generates models for', function () {
    $source = (string) file_get_contents(__DIR__ . '/../scripts/codegen.php');
    preg_match('/const OPERATIONS = \[(.*?)\];/s', $source, $match);
    preg_match_all("/'(\\w+)'/", $match[1], $ids);

    $covered = [];
    foreach (contract() as [, $method, $path]) {
        $segments = explode('/', $path);
        $covered[] = Kirora\Retry::operationFor($method, $segments)['id'] ?? "unknown $method $path";
    }
    sort($covered);
    $expected = $ids[1];
    sort($expected);
    expect($covered)->toBe($expected);
});

it('knows every operation in the spec', function () {
    expect(count(Operations::ALL))->toBeGreaterThan(100)
        ->and(Operations::ALL['PATCH /v1/contacts/{id}']['lookupSegment'])->toBe(3)
        ->and(Operations::ALL['POST /v1/events/batch']['entries'])->toBe('events');
});

it('pages sub-resources of a contact with all()', function () {
    $api = fakeApi([page([['id' => 'l1']], 'n'), page([['id' => 'l2']])]);
    $ids = array_map(fn ($l) => $l->id, iterator_to_array($api->client->contacts->lists->all('a b', ['idType' => 'email']), false));
    expect($ids)->toBe(['l1', 'l2'])
        ->and($api->request(1)->getUri()->getPath())->toBe('/v1/contacts/a%20b/lists')
        ->and($api->request(1)->getUri()->getQuery())->toBe('idType=email&cursor=n');
});

it('gives sub-resources of a contact as properties that are always the same objects', function () {
    $contacts = Kirora::client('re_test')->contacts;
    expect($contacts->lists)->toBeInstanceOf(Kirora\Service\Contacts\Lists::class)
        ->and($contacts->segments)->toBeInstanceOf(Kirora\Service\Contacts\Segments::class)
        ->and($contacts->topics)->toBeInstanceOf(Kirora\Service\Contacts\Topics::class)
        ->and($contacts->installations)->toBeInstanceOf(Kirora\Service\Contacts\Installations::class)
        ->and($contacts->lists)->toBe($contacts->lists);
});

it('pages a segment\'s membership changes with its query on every page', function () {
    $api = fakeApi([page([['id' => 'c1']], 'n'), page([['id' => 'c2']])]);
    $ids = array_map(fn ($c) => $c->id, iterator_to_array($api->client->segments->allMembershipChanges('s1', ['change' => 'left', 'day' => '2026-11-01']), false));
    expect($ids)->toBe(['c1', 'c2'])
        ->and($api->request(1)->getUri()->getQuery())->toBe('change=left&day=2026-11-01&cursor=n');
});
