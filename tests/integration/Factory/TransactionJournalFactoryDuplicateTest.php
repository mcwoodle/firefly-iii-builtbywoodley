<?php

/*
 * TransactionJournalFactoryDuplicateTest.php
 * Copyright (c) 2026 james@firefly-iii.org.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\integration\Factory;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;
use Override;
use Tests\integration\TestCase;

/**
 * Duplicate detection for imported transactions (error_if_duplicate_hash): a transaction is a duplicate
 * when the same account already has one with the same amount, currency, description and date, or when
 * the submitted data hashes the same. Transactions stored in the last minute are not considered.
 */
final class TransactionJournalFactoryDuplicateTest extends TestCase
{
    private int $assetId;
    private int $cardId;
    private int $otherAssetId;
    private TransactionGroupRepositoryInterface $repository;
    private User $user;

    public function testBothSidesOfATransferCanBeImportedFromTheirOwnAccounts(): void
    {
        // Card statement: a payment is a deposit into the card...
        $this->store(['description' => 'PAYMENT'] + $this->deposit());
        $this->travel(2)->minutes();
        // ...the account that paid it words the same payment differently.
        $this->store(['description' => 'Bill payment credit card', 'amount' => '250.00'] + $this->withdrawal());

        $this->assertSame(2, $this->countJournals());
    }

    public function testDeletedTransactionDoesNotBlockImportingItAgain(): void
    {
        $group = $this->store($this->withdrawal());
        $this->travel(2)->minutes();
        $this->repository->destroy($group);

        $this->store($this->withdrawal());

        $this->assertSame(1, $this->countJournals());
    }

    public function testDepositIsComparedOnItsDestinationAccount(): void
    {
        $this->store($this->deposit());
        $this->travel(2)->minutes();
        $this->store(['destination_id' => $this->assetId] + $this->deposit());

        $this->assertSame(2, $this->countJournals());

        $this->expectException(DuplicateTransactionException::class);
        $this->store($this->deposit());
    }

    public function testDifferentDescriptionDateAmountOrAccountIsNotADuplicate(): void
    {
        $this->store($this->withdrawal());
        $this->travel(2)->minutes();

        $this->store(['description' => 'Coffee shop #2'] + $this->withdrawal());
        $this->store(['date' => '2026-08-11'] + $this->withdrawal());
        $this->store(['amount' => '3.36'] + $this->withdrawal());
        $this->store(['source_id' => $this->otherAssetId] + $this->withdrawal());

        $this->assertSame(5, $this->countJournals());
    }

    public function testIdenticalTransactionsInOneImportAreAllStored(): void
    {
        $this->store($this->withdrawal());
        $this->store($this->withdrawal());

        $this->assertSame(2, $this->countJournals());
    }

    public function testOnlyTheImportedAccountIsCompared(): void
    {
        // The same expense from two accounts' statements is two transactions.
        $this->store($this->withdrawal());
        $this->travel(2)->minutes();
        $this->store(['source_id' => $this->otherAssetId] + $this->withdrawal());

        $this->assertSame(2, $this->countJournals());
    }

    public function testRenamedTransactionIsStillRejectedByHash(): void
    {
        $group = $this->store($this->withdrawal());
        $this->travel(2)->minutes();
        // what a "set description" rule would have done after storing:
        TransactionJournal::query()->where('transaction_group_id', $group->id)->update(['description' => 'Renamed by a rule']);

        $this->expectException(DuplicateTransactionException::class);
        $this->store($this->withdrawal());
    }

    public function testSameTransactionImportedAgainLaterIsRejected(): void
    {
        $this->store($this->withdrawal());
        $this->travel(2)->minutes();

        $this->expectException(DuplicateTransactionException::class);
        $this->store($this->withdrawal());
    }

    public function testSameTransactionWithOtherMetaDataIsStillRejected(): void
    {
        $this->store($this->withdrawal());
        $this->travel(2)->minutes();

        $this->expectException(DuplicateTransactionException::class);
        $this->store(['notes' => 'other notes', 'category_name' => 'Other category', 'book_date' => '2026-08-12'] + $this->withdrawal());
    }

    public function testWithoutTheFlagNothingIsRejected(): void
    {
        $this->store($this->withdrawal(), false);
        $this->travel(2)->minutes();
        $this->store($this->withdrawal(), false);

        $this->assertSame(2, $this->countJournals());
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createAuthenticatedUser();
        $this->actingAs($this->user);
        $this->assetId      = (int) Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create()->id;
        $this->otherAssetId = (int) Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create()->id;
        $this->cardId       = (int) Account::factory()->for($this->user)->withType(AccountTypeEnum::DEBT)->create()->id;
        $this->repository   = app(TransactionGroupRepositoryInterface::class);
        $this->repository->setUser($this->user);
    }

    private function countJournals(): int
    {
        return TransactionJournal::query()->where('user_id', $this->user->id)->count();
    }

    private function deposit(): array
    {
        return [
            'type'           => 'deposit',
            'date'           => '2026-08-18',
            'currency_code'  => 'EUR',
            'amount'         => '250.00',
            'description'    => 'Refund',
            'source_name'    => 'Some shop',
            'destination_id' => $this->cardId
        ];
    }

    private function store(array $transaction, bool $errorIfDuplicate = true): TransactionGroup
    {
        $transaction['date'] = Carbon::parse($transaction['date'], config('app.timezone'));

        return $this->repository->store([
            'user'                    => $this->user,
            'user_group'              => $this->user->userGroup,
            'group_title'             => null,
            'error_if_duplicate_hash' => $errorIfDuplicate,
            'apply_rules'             => false,
            'fire_webhooks'           => false,
            'transactions'            => [$transaction]
        ]);
    }

    private function withdrawal(): array
    {
        return [
            'type'             => 'withdrawal',
            'date'             => '2026-08-10',
            'currency_code'    => 'EUR',
            'amount'           => '3.35',
            'description'      => 'Coffee shop #1',
            'source_id'        => $this->assetId,
            'destination_name' => 'Coffee shop'
        ];
    }
}
