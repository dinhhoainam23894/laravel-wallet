<?php

namespace Bavix\Wallet\Test;

use Bavix\Wallet\Exceptions\AmountInvalid;
use Bavix\Wallet\Exceptions\BalanceIsEmpty;
use Bavix\Wallet\Interfaces\Mathable;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Simple\BCMath;
use Bavix\Wallet\Test\Models\UserFloat as User;

class WalletFloatTest extends TestCase
{

    /**
     * @return void
     */
    public function testDeposit(): void
    {
        $user = factory(User::class)->create();
        $this->assertEquals($user->balance, 0);
        $this->assertEquals($user->balanceFloat, 0);

        $user->depositFloat(.1);
        $this->assertEquals($user->balance, 10);
        $this->assertEquals($user->balanceFloat, .1);

        $user->depositFloat(1.25);
        $this->assertEquals($user->balance, 135);
        $this->assertEquals($user->balanceFloat, 1.35);

        $user->deposit(865);
        $this->assertEquals($user->balance, 1000);
        $this->assertEquals($user->balanceFloat, 10);

        $this->assertEquals($user->transactions()->count(), 3);

        $user->withdraw($user->balance);
        $this->assertEquals($user->balance, 0);
        $this->assertEquals($user->balanceFloat, 0);
    }

    /**
     * @return void
     */
    public function testInvalidDeposit(): void
    {
        $this->expectException(AmountInvalid::class);
        $this->expectExceptionMessageStrict(trans('wallet::errors.price_positive'));
        $user = factory(User::class)->create();
        $user->depositFloat(-1);
    }

    /**
     * @return void
     */
    public function testWithdraw(): void
    {
        $this->expectException(BalanceIsEmpty::class);
        $this->expectExceptionMessageStrict(trans('wallet::errors.wallet_empty'));

        $user = factory(User::class)->create();
        $this->assertEquals($user->balance, 0);

        $user->depositFloat(1);
        $this->assertEquals($user->balanceFloat, 1);

        $user->withdrawFloat(.1);
        $this->assertEquals($user->balanceFloat, 0.9);

        $user->withdrawFloat(.81);
        $this->assertEquals($user->balanceFloat, .09);

        $user->withdraw(9);
        $this->assertEquals($user->balance, 0);

        $user->withdraw(1);
    }

    /**
     * @return void
     */
    public function testInvalidWithdraw(): void
    {
        $this->expectException(BalanceIsEmpty::class);
        $this->expectExceptionMessageStrict(trans('wallet::errors.wallet_empty'));
        $user = factory(User::class)->create();
        $user->withdrawFloat(-1);
    }

    /**
     * @return void
     */
    public function testTransfer(): void
    {
        /**
         * @var User $first
         * @var User $second
         */
        list($first, $second) = factory(User::class, 2)->create();
        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals($first->balanceFloat, 0);
        $this->assertEquals($second->balanceFloat, 0);

        $first->depositFloat(1);
        $this->assertEquals($first->balanceFloat, 1);

        $second->depositFloat(1);
        $this->assertEquals($second->balanceFloat, 1);

        $first->transferFloat($second, 1);
        $this->assertEquals($first->balanceFloat, 0);
        $this->assertEquals($second->balanceFloat, 2);

        $second->transferFloat($first, 1);
        $this->assertEquals($second->balanceFloat, 1);
        $this->assertEquals($first->balanceFloat, 1);

        $second->transferFloat($first, 1);
        $this->assertEquals($second->balanceFloat, 0);
        $this->assertEquals($first->balanceFloat, 2);

        $first->withdrawFloat($first->balanceFloat);
        $this->assertEquals($first->balanceFloat, 0);

        $this->assertNull($first->safeTransferFloat($second, 1));
        $this->assertEquals($first->balanceFloat, 0);
        $this->assertEquals($second->balanceFloat, 0);

        $this->assertNotNull($first->forceTransferFloat($second, 1));
        $this->assertEquals($first->balanceFloat, -1);
        $this->assertEquals($second->balanceFloat, 1);

        $this->assertNotNull($second->forceTransferFloat($first, 1));
        $this->assertEquals($first->balanceFloat, 0);
        $this->assertEquals($second->balanceFloat, 0);
    }

    /**
     * @return void
     */
    public function testTransferYourself(): void
    {
        /**
         * @var User $user
         */
        $user = factory(User::class)->create();
        $this->assertEquals($user->balanceFloat, 0);

        $user->depositFloat(1);
        $user->transferFloat($user, 1);
        $this->assertEquals($user->balance, 100);

        $user->withdrawFloat($user->balanceFloat);
        $this->assertEquals($user->balance, 0);
    }

    /**
     * @return void
     */
    public function testBalanceIsEmpty(): void
    {
        $this->expectException(BalanceIsEmpty::class);
        $this->expectExceptionMessageStrict(trans('wallet::errors.wallet_empty'));

        /**
         * @var User $user
         */
        $user = factory(User::class)->create();
        $this->assertEquals($user->balance, 0);
        $user->withdrawFloat(1);
    }

    /**
     * @return void
     */
    public function testConfirmed(): void
    {
        /**
         * @var User $user
         */
        $user = factory(User::class)->create();
        $this->assertEquals($user->balance, 0);

        $user->depositFloat(1);
        $this->assertEquals($user->balanceFloat, 1);

        $user->withdrawFloat(1, null, false);
        $this->assertEquals($user->balanceFloat, 1);

        $this->assertTrue($user->canWithdrawFloat(1));
        $user->withdrawFloat(1);
        $this->assertFalse($user->canWithdrawFloat(1));
        $user->forceWithdrawFloat(1);
        $this->assertEquals($user->balanceFloat, -1);
        $user->depositFloat(1);
        $this->assertEquals($user->balanceFloat, 0);
    }

    /**
     * @return void
     */
    public function testMantissa(): void
    {
        /**
         * @var User $user
         */
        $user = factory(User::class)->create();
        $this->assertEquals($user->balance, 0);

        $user->deposit(1000000);
        $this->assertEquals($user->balance, 1000000);
        $this->assertEquals($user->balanceFloat, 10000.00);

        $transaction = $user->withdrawFloat(2556.72);
        $this->assertEquals($transaction->amount, -255672);
        $this->assertEquals($transaction->amountFloat, -2556.72);
        $this->assertEquals($transaction->type, Transaction::TYPE_WITHDRAW);

        $this->assertEquals($user->balance, 1000000 - 255672);
        $this->assertEquals($user->balanceFloat, 10000.00 - 2556.72);

        $transaction = $user->depositFloat(2556.72 * 2);
        $this->assertEquals($transaction->amount, 255672 * 2);
        $this->assertEquals($transaction->amountFloat, 2556.72 * 2);
        $this->assertEquals($transaction->type, Transaction::TYPE_DEPOSIT);

        $this->assertEquals($user->balance, 1000000 + 255672);
        $this->assertEquals($user->balanceFloat, 10000.00 + 2556.72);
    }

    /**
     * @return void
     */
    public function testUpdateTransaction(): void
    {
        /**
         * @var User $user
         */
        $user = factory(User::class)->create();
        $this->assertEquals($user->balance, 0);

        $user->deposit(1000000);
        $this->assertEquals($user->balance, 1000000);
        $this->assertEquals($user->balanceFloat, 10000.00);

        $transaction = $user->withdrawFloat(2556.72);
        $this->assertEquals($transaction->amount, -255672);
        $this->assertEquals($transaction->amountFloat, -2556.72);
        $this->assertEquals($transaction->type, Transaction::TYPE_WITHDRAW);

        $transaction->type = Transaction::TYPE_DEPOSIT;
        $transaction->amountFloat = 2556.72;
        $this->assertTrue($transaction->save());
        $this->assertTrue($user->wallet->refreshBalance());

        $this->assertEquals($transaction->amount, 255672);
        $this->assertEquals($transaction->amountFloat, 2556.72);
        $this->assertEquals($transaction->type, Transaction::TYPE_DEPOSIT);

        $this->assertEquals($user->balance, 1000000 + 255672);
        $this->assertEquals($user->balanceFloat, 10000.00 + 2556.72);
    }

    /**
     * @return void
     */
    public function testMathRounding(): void
    {
        /**
         * @var User $user
         */
        $user = factory(User::class)->create();
        $this->assertEquals($user->balance, 0);

        $user->deposit(1000000);
        $this->assertEquals($user->balance, 1000000);
        $this->assertEquals($user->balanceFloat, 10000.00);

        $transaction = $user->withdrawFloat(0.2 + 0.1);
        $this->assertEquals($transaction->amount, -30);
        $this->assertEquals($transaction->type, Transaction::TYPE_WITHDRAW);

        $transaction = $user->withdrawFloat(0.2 + 0.105);
        $this->assertEquals($transaction->amount, -31);
        $this->assertEquals($transaction->type, Transaction::TYPE_WITHDRAW);

        $transaction = $user->withdrawFloat(0.2 + 0.104);
        $this->assertEquals($transaction->amount, -30);
        $this->assertEquals($transaction->type, Transaction::TYPE_WITHDRAW);
    }

    /**
     * @return void
     */
    public function testEther(): void
    {
        if (app(Mathable::class) instanceof BCMath) {
            /**
             * @var User $user
             */
            $user = factory(User::class)->create();
            $this->assertEquals($user->balance, 0);

            $user->wallet->decimal_places = 18;
            $user->wallet->save();

            $math = app(Mathable::class);

            $user->depositFloat('545.8754855274419');
            $this->assertEquals($user->balance, '545875485527441900000');
            $this->assertEquals($math->compare($user->balanceFloat, '545.8754855274419'), 0);
        }
    }

    /**
     * @return void
     */
    public function testBitcoin(): void
    {
        if (app(Mathable::class) instanceof BCMath) {
            /**
             * @var User $user
             */
            $user = factory(User::class)->create();
            $this->assertEquals($user->balance, 0);

            $user->wallet->decimal_places = 32; // bitcoin wallet
            $user->wallet->save();

            $math = app(Mathable::class);

            for ($i = 0; $i < 256; $i++) {
                $user->depositFloat('0.00000001'); // Satoshi
            }

            $this->assertEquals($user->balance, '256' . str_repeat('0', 32 - 8));
            $this->assertEquals($math->compare($user->balanceFloat, '0.00000256'), 0);

            $user->deposit(256 . str_repeat('0', 32));
            $user->depositFloat('0.' . str_repeat('0', 31) . '1');

            [$q, $r] = explode('.', $user->balanceFloat, 2);
            $this->assertEquals(strlen($r), $user->wallet->decimal_places);
            $this->assertEquals($user->balance, '25600000256000000000000000000000001');
            $this->assertEquals($user->balanceFloat, '256.00000256000000000000000000000001');
        }
    }

    /**
     * Case from @ucanbehack
     * @see https://github.com/bavix/laravel-wallet/issues/149
     */
    public function testBitcoin2(): void
    {
        if (app(Mathable::class) instanceof BCMath) {
            /**
             * @var User $user
             */
            $user = factory(User::class)->create();
            $this->assertEquals($user->balance, 0);

            $user->wallet->decimal_places = 8;
            $user->wallet->save();

            $user->depositFloat(0.09699977);
            
            $user->wallet->refreshBalance();
            $user->refresh();

            $this->assertEquals($user->balanceFloat, 0.09699977);
            $this->assertEquals($user->balance, 9699977);
        }
    }

}
