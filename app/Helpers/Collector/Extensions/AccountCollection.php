<?php

/**
 * AccountCollection.php
 * Copyright (c) 2020 james@firefly-iii.org
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

namespace FireflyIII\Helpers\Collector\Extensions;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Override;

/**
 * Trait AccountCollection
 */
trait AccountCollection
{
    /**
     * A transfer created this many seconds ago (or less) is assumed to come from the same import run as
     * the transaction under inspection, so two identical transfers in one import both survive.
     */
    private const int DUPLICATE_TRANSFER_SAME_RUN_SECONDS = 60;

    #[Override]
    public function accountBalanceIs(string $direction, string $operator, string $value): GroupCollectorInterface
    {
        Log::warning(sprintf('GroupCollector will be SLOW: accountBalanceIs: "%s" "%s" "%s"', $direction, $operator, $value));

        /**
         * @param array $object
         *
         * @return bool
         */
        $filter              = static function (array $object) use ($direction, $operator, $value): bool {
            /** @var array $transaction */
            foreach ($object['transactions'] as $transaction) {
                $key       = sprintf('%s_account_id', $direction);
                $accountId = $transaction[$key] ?? 0;
                if (0 === $accountId) {
                    return false;
                }

                // in theory, this could lead to finding other users accounts.
                /** @var null|Account $account */
                $account   = Account::find($accountId);
                if (null === $account) {
                    continue;
                }

                // 2025-10-08 replace with accountsBalancesOptimized
                // the balance must be found BEFORE the transaction date.
                // so inclusive = false
                Log::debug(sprintf('accountBalanceIs: Call accountsBalancesOptimized with date/time "%s"', $transaction['date']->toIso8601String()));
                $balance   = Steam::accountsBalancesOptimized(
                    new Collection()->push($account),
                    $transaction['date'],
                    convertToPrimary: null,
                    inclusive: false
                )[$account->id];
                // $balance   = Steam::finalAccountBalance($account, $date);
                $result    = bccomp((string) $balance['balance'], $value);
                Log::debug(sprintf('"%s" vs "%s" is %d', $balance['balance'], $value, $result));

                switch ($operator) {
                    default:
                        Log::error(sprintf('GroupCollector: accountBalanceIs: unknown operator "%s"', $operator));

                        return false;

                    case '==':
                        Log::debug('Expect result to be 0 (equal)');

                        return 0 === $result;

                    case '!=':
                        Log::debug('Expect result to be -1 or 1 (not equal)');

                        return 0 !== $result;

                    case '>':
                        Log::debug('Expect result to be 1 (greater then)');

                        return 1 === $result;

                    case '>=':
                        Log::debug('Expect result to be 0 or 1 (greater then or equal)');

                        return -1 !== $result;

                    case '<':
                        Log::debug('Expect result to be -1 (less than)');

                        return -1 === $result;

                    case '<=':
                        Log::debug('Expect result to be -1 or 0 (less than or equal)');

                        return 1 !== $result;
                }

                // if($balance['balance'] $operator $value) {
                // }
            }

            return false;
        };
        $this->postFilters[] = $filter;

        return $this;
    }

    /**
     * These accounts must not be included.
     */
    public function excludeAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->whereNotIn('source.account_id', $accountIds);
            $this->query->whereNotIn('destination.account_id', $accountIds);

            Log::debug(sprintf('GroupCollector: excludeAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * These accounts must not be destination accounts.
     */
    public function excludeDestinationAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->whereNotIn('destination.account_id', $accountIds);

            Log::debug(sprintf('GroupCollector: excludeDestinationAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * These accounts must not be source accounts.
     */
    public function excludeSourceAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->whereNotIn('source.account_id', $accountIds);

            Log::debug(sprintf('GroupCollector: excludeSourceAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    #[Override]
    public function hasDuplicateTransfer(int $days): GroupCollectorInterface
    {
        Log::warning(sprintf('GroupCollector will be SLOW: hasDuplicateTransfer: %d', $days));
        $this->postFilters[] = static fn (array $object): bool => self::duplicateTransferExists($object, $days);

        return $this;
    }

    #[Override]
    public function hasNoDuplicateTransfer(int $days): GroupCollectorInterface
    {
        Log::warning(sprintf('GroupCollector will be SLOW: hasNoDuplicateTransfer: %d', $days));
        $this->postFilters[] = static fn (array $object): bool => !self::duplicateTransferExists($object, $days);

        return $this;
    }

    /**
     * Define which accounts can be part of the source and destination transactions.
     */
    public function setAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->where(static function (EloquentBuilder $query) use ($accountIds): void {
                $query->whereIn('source.account_id', $accountIds);
                $query->orWhereIn('destination.account_id', $accountIds);
            });

            // Log::debug(sprintf('GroupCollector: setAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * Both source AND destination must be in this list of accounts.
     */
    public function setBothAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->where(static function (EloquentBuilder $query) use ($accountIds): void {
                $query->whereIn('source.account_id', $accountIds);
                $query->whereIn('destination.account_id', $accountIds);
            });
            Log::debug(sprintf('GroupCollector: setBothAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * Define which accounts can be part of the source and destination transactions.
     */
    public function setDestinationAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->whereIn('destination.account_id', $accountIds);

            Log::debug(sprintf('GroupCollector: setDestinationAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * Define which accounts can NOT be part of the source and destination transactions.
     */
    public function setNotAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->where(static function (EloquentBuilder $query) use ($accountIds): void {
                $query->whereNotIn('source.account_id', $accountIds);
                $query->whereNotIn('destination.account_id', $accountIds);
            });

            // Log::debug(sprintf('GroupCollector: setAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * Define which accounts can be part of the source and destination transactions.
     */
    public function setSourceAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->whereIn('source.account_id', $accountIds);

            Log::debug(sprintf('GroupCollector: setSourceAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * Either account can be set, but NOT both. This effectively excludes internal transfers.
     */
    public function setXorAccounts(Collection $accounts): GroupCollectorInterface
    {
        if ($accounts->count() > 0) {
            $accountIds = $accounts->pluck('id')->toArray();
            $this->query->where(static function (EloquentBuilder $q1) use ($accountIds): void {
                // sourceAccount is in the set, and destination is NOT.

                $q1->where(static function (EloquentBuilder $q2) use ($accountIds): void {
                    $q2->whereIn('source.account_id', $accountIds);
                    $q2->whereNotIn('destination.account_id', $accountIds);
                });
                // destination is in the set, and source is NOT
                $q1->orWhere(static function (EloquentBuilder $q3) use ($accountIds): void {
                    $q3->whereNotIn('source.account_id', $accountIds);
                    $q3->whereIn('destination.account_id', $accountIds);
                });
            });

            Log::debug(sprintf('GroupCollector: setXorAccounts: %s', implode(', ', $accountIds)));
        }

        return $this;
    }

    /**
     * Will include the source and destination account names and types.
     */
    public function withAccountInformation(): GroupCollectorInterface
    {
        if (false === $this->hasAccountInfo) {
            // join source account table
            $this->query->leftJoin('accounts as source_account', 'source_account.id', '=', 'source.account_id');
            // join source account type table
            $this->query->leftJoin('account_types as source_account_type', 'source_account_type.id', '=', 'source_account.account_type_id');

            // add source account fields:
            $this->fields[]       = 'source_account.name as source_account_name';
            $this->fields[]       = 'source_account.iban as source_account_iban';
            $this->fields[]       = 'source_account_type.type as source_account_type';

            // same for dest
            $this->query->leftJoin('accounts as dest_account', 'dest_account.id', '=', 'destination.account_id');
            $this->query->leftJoin('account_types as dest_account_type', 'dest_account_type.id', '=', 'dest_account.account_type_id');

            // and add fields:
            $this->fields[]       = 'dest_account.name as destination_account_name';
            $this->fields[]       = 'dest_account.iban as destination_account_iban';
            $this->fields[]       = 'dest_account_type.type as destination_account_type';
            $this->hasAccountInfo = true;
        }

        return $this;
    }

    /**
     * True when another (older) transfer between the same accounts, for the same amount and currency,
     * is dated within $days days of this one. Used to catch a transfer imported from both accounts.
     */
    private static function duplicateTransferExists(array $object, int $days): bool
    {
        /** @var array $transaction */
        foreach ($object['transactions'] as $transaction) {
            if (TransactionTypeEnum::TRANSFER->value !== $transaction['transaction_type_type']) {
                continue;
            }

            /** @var Carbon $date */
            $date   = $transaction['date'];
            $exists = TransactionJournal::query()
                ->leftJoin('transactions as source', static function (JoinClause $join): void {
                    $join->on('source.transaction_journal_id', '=', 'transaction_journals.id')->where('source.amount', '<', 0);
                })
                ->leftJoin('transactions as destination', static function (JoinClause $join): void {
                    $join->on('destination.transaction_journal_id', '=', 'transaction_journals.id')->where('destination.amount', '>', 0);
                })
                ->where('transaction_journals.user_group_id', $transaction['user_group_id'])
                ->where('transaction_journals.transaction_type_id', $transaction['transaction_type_id'])
                ->where('transaction_journals.id', '!=', $transaction['transaction_journal_id'])
                ->where('transaction_journals.created_at', '<', Carbon::now()->subSeconds(self::DUPLICATE_TRANSFER_SAME_RUN_SECONDS))
                ->where('transaction_journals.date', '>=', $date->clone()->subDays($days)->format('Y-m-d 00:00:00'))
                ->where('transaction_journals.date', '<=', $date->clone()->addDays($days)->format('Y-m-d 23:59:59'))
                ->whereNull('source.deleted_at')
                ->whereNull('destination.deleted_at')
                ->where('source.account_id', $transaction['source_account_id'])
                ->where('destination.account_id', $transaction['destination_account_id'])
                ->where('source.amount', $transaction['amount'])
                ->where('source.transaction_currency_id', $transaction['currency_id'])
                ->exists();
            Log::debug(sprintf('duplicateTransferExists: journal #%d, %d day(s): %s', $transaction['transaction_journal_id'], $days, var_export($exists, true)));
            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
