<?php

namespace App\Console\Commands;

use App\Exceptions\InternalException;
use App\Models\BetHistories;
use App\Models\Member;
use App\Models\SystemConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GameHistories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:histories';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get bet history';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $config = SystemConfig::getConfigGroup('remote_api');

        if (
            !array_key_exists('remote_api_domain', $config)
            || !array_key_exists('remote_api_id', $config)
            && !array_key_exists('remote_api_key', $config)
        ) {
            throw new InternalException(trans('res.api.game.api_parameter_err'));
        }

        try {
            $last = BetHistories::orderBy('id', 'desc')->first();
            $versionKey = 0;

            if (!empty($last)) {
                $versionKey = (int)$last->versionkey;
            }

            $operatorCode = $config['remote_api_id'];
            $signature = strtoupper(MD5($operatorCode . data_get($config, 'remote_api_key')));

            $dataQuery = array(
                'operatorcode' => $operatorCode,
                'versionkey' => $versionKey,
                'signature' => $signature,
            );
            $url = configPlatform('domain_api') . '/fetchbykey.aspx?' . http_build_query($dataQuery);


            $result = curl_geturl($url);
            $res = json_decode($result, true);

            foreach (json_decode($res['result']) as $item) {
                $item = (array)($item);
                $entity = BetHistories::where('bet_id', $item['id'])->first();
                $resultBetStatus = BetHistories::RESULT_BET_STATUS_DRAW;

                if ($item['bet'] > $item['payout']) {
                    $resultBetStatus = BetHistories::RESULT_BET_STATUS_LOSE;
                }

                if ($item['bet'] < $item['payout']) {
                    $resultBetStatus = BetHistories::RESULT_BET_STATUS_WIN;
                }

                $member = Member::where('name', $item['member'])->first();
                $memberId = !empty($member) ? $member->id : -1;

                if (empty($entity)) {
                    // Store
                    BetHistories::create([
                        'bet_id' => $item['id'],
                        'bet_ref_no' => $item['ref_no'],
                        'bet_product' => $item['product'],
                        'api_name' => $item['site'],
                        'member_id' => $memberId,
                        'member_name' => $item['member'],
                        'bet_game_id' => $item['game_id'] ? $item['game_id'] : '',
                        'bet_start_time' => utcToVn($item['start_time']),
                        'bet_end_time' => utcToVn($item['end_time']),
                        'turnover' => $item['turnover'],
                        'bet' => $item['bet'],
                        'payout' => $item['payout'],
                        'commission' => $item['commission'],
                        'status' => $item['status'],
                        'versionkey' => data_get($res, 'lastversionkey'),
                        'result_bet_status' => $resultBetStatus,
                    ]);
                } else {
                    // Update
                    BetHistories::where('bet_id', $item['id'])->update([
                        'bet_id' => $item['id'],
                        'bet_ref_no' => $item['ref_no'],
                        'bet_product' => $item['product'],
                        'api_name' => $item['site'],
                        'member_id' => $memberId,
                        'member_name' => $item['member'],
                        'bet_game_id' => $item['game_id'] ? $item['game_id'] : '',
                        'bet_start_time' => utcToVn($item['start_time']),
                        'bet_end_time' => utcToVn($item['end_time']),
                        'turnover' => $item['turnover'],
                        'bet' => $item['bet'],
                        'payout' => $item['payout'],
                        'commission' => $item['commission'],
                        'status' => $item['status'],
                        'versionkey' => data_get($res, 'lastversionkey'),
                        'result_bet_status' => $resultBetStatus,
                    ]);
                }
            }

            Log::info('SUCCESS: Get bet history success at: ', [date('d-m-Y H:i:s')]);
        } catch (\Exception $e) {
            Log::info('ERROR: Get bet history error at: ', [date('d-m-Y H:i:s')]);
            Log::error($e);
        }

        return true;
    }
}
