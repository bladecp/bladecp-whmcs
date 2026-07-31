<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Shared;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Updates WHMCS usage and quota fields for BladeCP services. */
final class UsageUpdate
{
    /** Service custom field that stores the provision UUID. */
    public const PROVISION_UUID_FIELD = 'BladeCP Provision UUID';
    /** Limits concurrent requests during the scheduled update. */
    private const REQUEST_BATCH_SIZE = 8;

    public static function run(array $params): string
    {
        // Return successfully when the WHMCS database is unavailable.
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return 'success';
        }
        $client = Client::fromParams($params);
        // Return successfully until a server credential is configured.
        if ($client->configError() !== null) {
            return 'success';
        }

        $serverId = (int) ($params['serverid'] ?? 0);
        $services = self::servicesWithUuid($serverId);

        $updated = 0;
        $failed = 0;
        $skipped = 0;
        foreach (array_chunk($services, self::REQUEST_BATCH_SIZE) as $batch) {
            $responses = $client->getMany(array_map(
                static fn (array $service): string => '/provisions/'
                    . rawurlencode($service['uuid']) . '/usage',
                $batch
            ));
            foreach ($batch as $index => $svc) {
                $uuid = $svc['uuid'];
                $serviceId = $svc['serviceId'];
                $usage = $responses[$index] ?? null;
                if (!is_array($usage)) {
                    $failed++;
                    Logger::logCall(
                        'UsageUpdateUnavailable',
                        ['uuid' => $uuid, 'serviceId' => $serviceId],
                        ['error' => 'A API não devolveu uso utilizável para este serviço.']
                    );
                    continue;
                }
                try {
                    $cols = self::toHostingUsage($usage);
                    if ($cols === []) {
                        $skipped++;
                        continue;
                    }
                    $cols['lastupdate'] = date('Y-m-d H:i:s');
                    \WHMCS\Database\Capsule::table('tblhosting')
                        ->where('id', $serviceId)
                        ->update($cols);
                    $updated++;
                } catch (\Throwable $e) {
                    // Continue processing the remaining services.
                    $failed++;
                    Logger::logCall(
                        'UsageUpdate',
                        ['uuid' => $uuid, 'serviceId' => $serviceId],
                        ['error' => $e->getMessage()]
                    );
                }
            }
        }

        Logger::logCall(
            'UsageUpdate',
            ['serverId' => $serverId, 'services' => count($services)],
            [
                'updated' => $updated,
                'failed' => $failed,
                'skipped' => $skipped,
                'batches' => (int) ceil(count($services) / self::REQUEST_BATCH_SIZE),
            ]
        );
        return 'success';
    }

    /**
     * @param array<string,mixed> $usage
     * @return array<string,int>
     */
    public static function toHostingUsage(array $usage): array
    {
        $data = $usage['data'] ?? null;
        if (is_array($data) && $data !== [] && !array_is_list($data)) {
            $usage = $data;
        }

        // Current use and plan limits are mapped independently.
        $current = is_array($usage['usage'] ?? null) ? $usage['usage'] : $usage;
        $plan = is_array($usage['plan'] ?? null) ? $usage['plan'] : [];

        $out = [];
        $disk = self::mb(
            $current,
            ['diskUsedMb', 'disk_used_mb', 'diskMb', 'disk_mb'],
            ['diskUsedGb', 'disk_used_gb', 'diskGb', 'disk_gb', 'diskUsageGb']
        );
        if ($disk !== null) {
            $out['diskusage'] = $disk;
        }
        $diskLimit = self::mb(
            $plan,
            ['diskLimitMb', 'disk_limit_mb'],
            ['diskLimitGb', 'disk_limit_gb', 'diskGb', 'disk_gb']
        );
        if ($diskLimit !== null) {
            $out['disklimit'] = $diskLimit;
        }
        $bw = self::mb(
            $current,
            ['bandwidthUsedMb', 'bandwidth_used_mb', 'bwUsedMb', 'bwMb'],
            ['bandwidthUsedGb', 'bandwidthGbMonth', 'bandwidth_gb_month', 'bandwidthGb', 'bwGb']
        );
        if ($bw !== null) {
            $out['bwusage'] = $bw;
        }
        $bwLimit = self::mb(
            $plan,
            ['bandwidthLimitMb', 'bandwidth_limit_mb', 'bwLimitMb'],
            [
                'bandwidthLimitGb', 'bandwidth_limit_gb', 'bwLimitGb',
                'bandwidthGbMonth', 'bandwidth_gb_month',
            ]
        );
        if ($bwLimit !== null) {
            $out['bwlimit'] = $bwLimit;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $usage
     * @param string[] $mbKeys
     * @param string[] $gbKeys
     */
    private static function mb(array $usage, array $mbKeys, array $gbKeys): ?int
    {
        foreach ($mbKeys as $k) {
            if (isset($usage[$k]) && is_numeric($usage[$k])) {
                return (int) round((float) $usage[$k]);
            }
        }
        foreach ($gbKeys as $k) {
            if (isset($usage[$k]) && is_numeric($usage[$k])) {
                return (int) round((float) $usage[$k] * 1024);
            }
        }
        return null;
    }

    /** @return array<int,array{serviceId:int,uuid:string}> */
    private static function servicesWithUuid(int $serverId): array
    {
        try {
            $q = \WHMCS\Database\Capsule::table('tblhosting as h')
                ->join('tblcustomfieldsvalues as v', 'v.relid', '=', 'h.id')
                ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                ->where('f.fieldname', self::PROVISION_UUID_FIELD)
                ->where('v.value', '!=', '')
                ->whereIn('h.domainstatus', ['Active', 'Suspended'])
                ->select('h.id as serviceId', 'v.value as uuid');
            if ($serverId > 0) {
                $q->where('h.server', $serverId);
            }
            $out = [];
            foreach ($q->get() as $row) {
                $uuid = trim((string) ($row->uuid ?? ''));
                $sid = (int) ($row->serviceId ?? 0);
                if ($uuid !== '' && $sid > 0) {
                    $out[] = ['serviceId' => $sid, 'uuid' => $uuid];
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
