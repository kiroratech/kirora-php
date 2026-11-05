<?php

declare(strict_types=1);

use Kirora\Models\Campaign;
use Kirora\Models\CampaignContent;
use Kirora\Models\ContactConsent;
use Kirora\Models\ContactDetail;
use Kirora\Models\ContactRfm;
use Kirora\Models\Money;

it('reads fields as properties and array keys', function () {
    $contact = ContactDetail::from(['id' => 'c1', 'email' => 'ada@example.com', 'phone' => null]);
    expect($contact->id)->toBe('c1')
        ->and($contact['email'])->toBe('ada@example.com')
        ->and($contact->phone)->toBeNull()
        ->and($contact->missing)->toBeNull()
        ->and(isset($contact->email))->toBeTrue()
        ->and(isset($contact->phone))->toBeFalse()
        ->and(isset($contact['missing']))->toBeFalse()
        ->and($contact->lastResponse())->toBeNull();
});

it('turns nested objects into models', function () {
    $contact = ContactDetail::from([
        'id' => 'c1',
        'rfm' => ['group' => 'loyal', 'totalSpent' => ['currency' => 'USD', 'value' => 412.5]],
        'consent' => ['email' => ['status' => 'subscribed']],
        'attributes' => ['plan' => 'pro'],
    ]);
    expect($contact->rfm)->toBeInstanceOf(ContactRfm::class)
        ->and($contact->rfm->totalSpent)->toBeInstanceOf(Money::class)
        ->and($contact->rfm->totalSpent->value)->toBe(412.5)
        ->and($contact->consent)->toBeInstanceOf(ContactConsent::class)
        ->and($contact->attributes)->toBe(['plan' => 'pro']);

    $campaign = Campaign::from(['id' => 'cmp', 'content' => ['subject' => 'Hi'], 'abTest' => null]);
    expect($campaign->content)->toBeInstanceOf(CampaignContent::class)->and($campaign->abTest)->toBeNull();
});

it('keeps fields the SDK doesn\'t know', function () {
    $contact = ContactDetail::from(['id' => 'c1', 'brandNew' => ['x' => 1]]);
    expect($contact->brandNew)->toBe(['x' => 1]);
});

it('converts back to arrays and JSON', function () {
    $fields = ['id' => 'c1', 'rfm' => ['group' => 'loyal', 'totalSpent' => ['currency' => 'USD', 'value' => 1.5]]];
    $contact = ContactDetail::from($fields);
    expect($contact->toArray())->toBe($fields)
        ->and(json_decode($contact->toJson(), true))->toBe($fields)
        ->and(json_decode(json_encode($contact), true))->toBe($fields);
});

it('is read-only', function () {
    $contact = ContactDetail::from(['id' => 'c1']);
    expect(fn () => $contact->id = 'x')->toThrow(BadMethodCallException::class)
        ->and(fn () => $contact['id'] = 'x')->toThrow(BadMethodCallException::class)
        ->and(function () use ($contact) {
            unset($contact['id']);
        })->toThrow(BadMethodCallException::class)
        ->and($contact->id)->toBe('c1');
});

it('hydrates responses into models', function () {
    $api = fakeApi([data(['id' => 'c1', 'consent' => ['email' => []]])]);
    $contact = $api->client->contacts->get('c1');
    expect($contact)->toBeInstanceOf(ContactDetail::class)
        ->and($contact->consent)->toBeInstanceOf(ContactConsent::class)
        ->and($contact->lastResponse()?->status)->toBe(200);
});
