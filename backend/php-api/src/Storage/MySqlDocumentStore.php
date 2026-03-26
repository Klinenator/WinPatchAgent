<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

use PatchAgent\Api\Support\Json;
use PDO;
use PDOException;

final class MySqlDocumentStore
{
    private PDO $pdo;
    private string $documentsTable;
    private string $agentsTable;
    private string $jobsTable;
    private string $enrollmentsTable;
    private string $inventoryTable;
    private string $eventsTable;
    private string $adminUsersTable;
    private string $adminPasskeysTable;
    private string $automationsTable;

    public function __construct(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        string $tableName = 'patchapi_documents'
    ) {
        $dbHost = trim($host);
        $dbName = trim($database);
        $dbUser = trim($username);
        $safeDocumentsTable = trim($tableName);

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new \RuntimeException('MySQL storage requires host, database name, and username.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $safeDocumentsTable)) {
            throw new \RuntimeException('PATCH_API_DB_TABLE may contain only letters, numbers, and underscore.');
        }

        $this->documentsTable = $safeDocumentsTable;
        $prefix = preg_replace('/_documents$/', '', $safeDocumentsTable);
        if (!is_string($prefix) || trim($prefix) === '') {
            $prefix = 'patchapi';
        }

        $this->agentsTable = $prefix . '_agents';
        $this->jobsTable = $prefix . '_jobs';
        $this->enrollmentsTable = $prefix . '_enrollments';
        $this->inventoryTable = $prefix . '_inventory';
        $this->eventsTable = $prefix . '_events';
        $this->adminUsersTable = $prefix . '_admin_users';
        $this->adminPasskeysTable = $prefix . '_admin_passkeys';
        $this->automationsTable = $prefix . '_automation_profiles';

        foreach ([
            $this->agentsTable,
            $this->jobsTable,
            $this->enrollmentsTable,
            $this->inventoryTable,
            $this->eventsTable,
            $this->adminUsersTable,
            $this->adminPasskeysTable,
            $this->automationsTable,
        ] as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                throw new \RuntimeException('Derived MySQL table name is invalid.');
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbHost,
            max(1, $port),
            $dbName
        );

        try {
            $this->pdo = new PDO($dsn, $dbUser, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new \RuntimeException(
                'Failed to connect to MySQL storage: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->ensureSchema();
    }

    public function readJson(string $relativePath, array $default = []): array
    {
        $path = $this->normalizePath($relativePath);

        if ($path === 'agents.json') {
            return ['agents' => $this->readAgentsRecords()];
        }

        if ($path === 'jobs.json') {
            return ['jobs' => $this->readJobRecords()];
        }

        if ($path === 'enrollments.json') {
            return ['enrollments' => $this->readEnrollmentRecords()];
        }

        if ($path === 'admin_users.json') {
            return ['users' => $this->readAdminUsersMap()];
        }

        if ($path === 'admin_passkeys.json') {
            return ['users' => $this->readAdminPasskeysMap()];
        }

        if ($path === 'automation_profiles.json') {
            return ['profiles' => $this->readAutomationProfiles()];
        }

        if (preg_match('#^inventory/([^/]+)\.json$#', $path, $matches) === 1) {
            $snapshot = $this->readInventorySnapshot((string) $matches[1]);
            return is_array($snapshot) ? $snapshot : $default;
        }

        $content = $this->readRawDocument($path);
        if ($content === null || trim($content) === '') {
            return $default;
        }

        $decoded = Json::decodeObject($content);
        return is_array($decoded) ? $decoded : $default;
    }

    public function writeJson(string $relativePath, array $data): void
    {
        $path = $this->normalizePath($relativePath);

        if ($path === 'agents.json') {
            $this->writeAgentsRecords($data);
            return;
        }

        if ($path === 'jobs.json') {
            $this->writeJobRecords($data);
            return;
        }

        if ($path === 'enrollments.json') {
            $this->writeEnrollmentRecords($data);
            return;
        }

        if ($path === 'admin_users.json') {
            $this->writeAdminUsersRecords($data);
            return;
        }

        if ($path === 'admin_passkeys.json') {
            $this->writeAdminPasskeysRecords($data);
            return;
        }

        if ($path === 'automation_profiles.json') {
            $this->writeAutomationProfiles($data);
            return;
        }

        if (preg_match('#^inventory/([^/]+)\.json$#', $path, $matches) === 1) {
            $this->writeInventorySnapshot((string) $matches[1], $data);
            return;
        }

        $this->writeRawDocument($path, Json::encode($data));
    }

    public function writeRaw(string $relativePath, string $content): void
    {
        $path = $this->normalizePath($relativePath);

        if (preg_match('#^events/([^/]+)\.ndjson$#', $path, $matches) === 1) {
            $agentRecordId = (string) $matches[1];
            $this->replaceEventsFromNdjson($agentRecordId, $content);
            return;
        }

        if (str_ends_with($path, '.json')) {
            $decoded = Json::decodeObject($content);
            if (is_array($decoded)) {
                $this->writeJson($path, $decoded);
                return;
            }
        }

        $this->writeRawDocument($path, $content);
    }

    public function appendLine(string $relativePath, array $data): void
    {
        $path = $this->normalizePath($relativePath);

        if (preg_match('#^events/([^/]+)\.ndjson$#', $path, $matches) === 1) {
            $agentRecordId = (string) $matches[1];
            $deviceId = trim((string) ($data['device_id'] ?? ''));
            $recordedAt = trim((string) ($data['recorded_at'] ?? ''));
            $event = is_array($data['event'] ?? null) ? $data['event'] : [];
            if ($deviceId === '') {
                $deviceId = 'unknown-device';
            }
            if ($recordedAt === '') {
                $recordedAt = gmdate(DATE_ATOM);
            }

            $sql = sprintf(
                'INSERT INTO `%s` (`agent_record_id`, `device_id`, `recorded_at`, `event_json`) VALUES (:agent_record_id, :device_id, :recorded_at, :event_json)',
                $this->eventsTable
            );
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                ':agent_record_id' => $agentRecordId,
                ':device_id' => $deviceId,
                ':recorded_at' => $recordedAt,
                ':event_json' => Json::encode($event),
            ]);
            return;
        }

        $line = Json::encode($data) . PHP_EOL;
        $sql = sprintf(
            'INSERT INTO `%s` (`path`, `content`, `created_at`, `updated_at`)
             VALUES (:path, :line, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE `content` = CONCAT(`content`, VALUES(`content`)), `updated_at` = CURRENT_TIMESTAMP(6)',
            $this->documentsTable
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':path' => $path,
            ':line' => $line,
        ]);
    }

    public function exists(string $relativePath): bool
    {
        $path = $this->normalizePath($relativePath);

        if (preg_match('#^inventory/([^/]+)\.json$#', $path, $matches) === 1) {
            $sql = sprintf('SELECT 1 FROM `%s` WHERE `agent_record_id` = :agent_record_id LIMIT 1', $this->inventoryTable);
            $statement = $this->pdo->prepare($sql);
            $statement->execute([':agent_record_id' => (string) $matches[1]]);
            return $statement->fetchColumn() !== false;
        }

        if (preg_match('#^events/([^/]+)\.ndjson$#', $path, $matches) === 1) {
            $sql = sprintf('SELECT 1 FROM `%s` WHERE `agent_record_id` = :agent_record_id LIMIT 1', $this->eventsTable);
            $statement = $this->pdo->prepare($sql);
            $statement->execute([':agent_record_id' => (string) $matches[1]]);
            return $statement->fetchColumn() !== false;
        }

        if ($path === 'agents.json') {
            return $this->tableHasRows($this->agentsTable);
        }

        if ($path === 'jobs.json') {
            return $this->tableHasRows($this->jobsTable);
        }

        if ($path === 'enrollments.json') {
            return $this->tableHasRows($this->enrollmentsTable);
        }

        if ($path === 'admin_users.json') {
            return $this->tableHasRows($this->adminUsersTable);
        }

        if ($path === 'admin_passkeys.json') {
            return $this->tableHasRows($this->adminPasskeysTable);
        }

        if ($path === 'automation_profiles.json') {
            return $this->tableHasRows($this->automationsTable);
        }

        $sql = sprintf(
            'SELECT 1 FROM `%s` WHERE `path` = :path LIMIT 1',
            $this->documentsTable
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':path' => $path]);
        return $statement->fetchColumn() !== false;
    }

    public function readRaw(string $relativePath): ?string
    {
        $path = $this->normalizePath($relativePath);

        if (preg_match('#^events/([^/]+)\.ndjson$#', $path, $matches) === 1) {
            $agentRecordId = (string) $matches[1];
            $sql = sprintf(
                'SELECT `recorded_at`, `device_id`, `event_json` FROM `%s` WHERE `agent_record_id` = :agent_record_id ORDER BY `event_id` ASC',
                $this->eventsTable
            );
            $statement = $this->pdo->prepare($sql);
            $statement->execute([':agent_record_id' => $agentRecordId]);
            $rows = $statement->fetchAll();
            if (!is_array($rows) || count($rows) === 0) {
                return null;
            }

            $lines = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $event = $this->decodeJsonArray((string) ($row['event_json'] ?? ''));
                $lines[] = Json::encode([
                    'recorded_at' => (string) ($row['recorded_at'] ?? ''),
                    'agent_record_id' => $agentRecordId,
                    'device_id' => (string) ($row['device_id'] ?? ''),
                    'event' => $event,
                ]);
            }

            return implode(PHP_EOL, $lines) . PHP_EOL;
        }

        if (
            $path === 'agents.json'
            || $path === 'jobs.json'
            || $path === 'enrollments.json'
            || $path === 'admin_users.json'
            || $path === 'admin_passkeys.json'
            || $path === 'automation_profiles.json'
            || preg_match('#^inventory/[^/]+\.json$#', $path) === 1
        ) {
            return Json::encode($this->readJson($path, []));
        }

        return $this->readRawDocument($path);
    }

    private function writeAgentsRecords(array $data): void
    {
        $agents = is_array($data['agents'] ?? null) ? $data['agents'] : [];

        $deleteSql = sprintf('DELETE FROM `%s`', $this->agentsTable);
        $insertSql = sprintf(
            'INSERT INTO `%s`
                (`agent_record_id`, `device_id`, `hostname`, `display_name`, `domain_name`, `os_json`, `agent_json`, `capabilities_json`, `token_hash`, `created_at`, `updated_at`, `last_seen_at`, `last_heartbeat_json`)
             VALUES
                (:agent_record_id, :device_id, :hostname, :display_name, :domain_name, :os_json, :agent_json, :capabilities_json, :token_hash, :created_at, :updated_at, :last_seen_at, :last_heartbeat_json)',
            $this->agentsTable
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($deleteSql);
            $statement = $this->pdo->prepare($insertSql);

            foreach ($agents as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $agentRecordId = trim((string) ($record['agent_record_id'] ?? ''));
                if ($agentRecordId === '') {
                    continue;
                }

                $hostname = trim((string) ($record['hostname'] ?? ''));
                $statement->execute([
                    ':agent_record_id' => $agentRecordId,
                    ':device_id' => trim((string) ($record['device_id'] ?? '')),
                    ':hostname' => $hostname,
                    ':display_name' => trim((string) ($record['display_name'] ?? $hostname)),
                    ':domain_name' => trim((string) ($record['domain'] ?? '')),
                    ':os_json' => Json::encode(is_array($record['os'] ?? null) ? $record['os'] : []),
                    ':agent_json' => Json::encode(is_array($record['agent'] ?? null) ? $record['agent'] : []),
                    ':capabilities_json' => Json::encode(is_array($record['capabilities'] ?? null) ? $record['capabilities'] : []),
                    ':token_hash' => trim((string) ($record['token_hash'] ?? '')),
                    ':created_at' => trim((string) ($record['created_at'] ?? gmdate(DATE_ATOM))),
                    ':updated_at' => trim((string) ($record['updated_at'] ?? gmdate(DATE_ATOM))),
                    ':last_seen_at' => $this->nullableText($record['last_seen_at'] ?? null),
                    ':last_heartbeat_json' => Json::encode(is_array($record['last_heartbeat'] ?? null) ? $record['last_heartbeat'] : []),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readAgentsRecords(): array
    {
        $sql = sprintf('SELECT * FROM `%s` ORDER BY `created_at` ASC, `agent_record_id` ASC', $this->agentsTable);
        $rows = $this->pdo->query($sql)->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $records[] = [
                'agent_record_id' => (string) ($row['agent_record_id'] ?? ''),
                'device_id' => (string) ($row['device_id'] ?? ''),
                'hostname' => (string) ($row['hostname'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'domain' => (string) ($row['domain_name'] ?? ''),
                'os' => $this->decodeJsonArray((string) ($row['os_json'] ?? '')),
                'agent' => $this->decodeJsonArray((string) ($row['agent_json'] ?? '')),
                'capabilities' => $this->decodeJsonArray((string) ($row['capabilities_json'] ?? '')),
                'token_hash' => (string) ($row['token_hash'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
                'last_heartbeat' => $this->decodeJsonArray((string) ($row['last_heartbeat_json'] ?? '')),
            ];
        }

        return $records;
    }

    private function writeJobRecords(array $data): void
    {
        $jobs = is_array($data['jobs'] ?? null) ? $data['jobs'] : [];

        $deleteSql = sprintf('DELETE FROM `%s`', $this->jobsTable);
        $insertSql = sprintf(
            'INSERT INTO `%s`
                (`job_id`, `position_index`, `type`, `correlation_id`, `status`, `target_agent_id`, `target_device_id`, `created_at`, `updated_at`, `acknowledged_at`, `completed_at`, `canceled_at`, `record_json`)
             VALUES
                (:job_id, :position_index, :type, :correlation_id, :status, :target_agent_id, :target_device_id, :created_at, :updated_at, :acknowledged_at, :completed_at, :canceled_at, :record_json)',
            $this->jobsTable
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($deleteSql);
            $statement = $this->pdo->prepare($insertSql);

            foreach (array_values($jobs) as $position => $record) {
                if (!is_array($record)) {
                    continue;
                }

                $jobId = trim((string) ($record['job_id'] ?? ''));
                if ($jobId === '') {
                    continue;
                }

                $statement->execute([
                    ':job_id' => $jobId,
                    ':position_index' => $position,
                    ':type' => trim((string) ($record['type'] ?? '')),
                    ':correlation_id' => trim((string) ($record['correlation_id'] ?? '')),
                    ':status' => trim((string) ($record['status'] ?? 'assigned')),
                    ':target_agent_id' => trim((string) ($record['target_agent_id'] ?? '')),
                    ':target_device_id' => trim((string) ($record['target_device_id'] ?? '')),
                    ':created_at' => trim((string) ($record['created_at'] ?? gmdate(DATE_ATOM))),
                    ':updated_at' => $this->nullableText($record['updated_at'] ?? null),
                    ':acknowledged_at' => $this->nullableText($record['acknowledged_at'] ?? null),
                    ':completed_at' => $this->nullableText($record['completed_at'] ?? null),
                    ':canceled_at' => $this->nullableText($record['canceled_at'] ?? null),
                    ':record_json' => Json::encode($record),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readJobRecords(): array
    {
        $sql = sprintf(
            'SELECT `record_json` FROM `%s` ORDER BY `position_index` ASC, `seq` ASC',
            $this->jobsTable
        );
        $rows = $this->pdo->query($sql)->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $record = Json::decodeObject((string) ($row['record_json'] ?? '{}'));
            if (!is_array($record)) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    private function writeEnrollmentRecords(array $data): void
    {
        $enrollments = is_array($data['enrollments'] ?? null) ? $data['enrollments'] : [];

        $deleteSql = sprintf('DELETE FROM `%s`', $this->enrollmentsTable);
        $insertSql = sprintf(
            'INSERT INTO `%s`
                (`enrollment_id`, `platform`, `key_hash`, `created_at`, `updated_at`, `expires_at`, `used_at`, `used_by_device_id`)
             VALUES
                (:enrollment_id, :platform, :key_hash, :created_at, :updated_at, :expires_at, :used_at, :used_by_device_id)',
            $this->enrollmentsTable
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($deleteSql);
            $statement = $this->pdo->prepare($insertSql);

            foreach ($enrollments as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $enrollmentId = trim((string) ($record['enrollment_id'] ?? ''));
                if ($enrollmentId === '') {
                    continue;
                }

                $statement->execute([
                    ':enrollment_id' => $enrollmentId,
                    ':platform' => trim((string) ($record['platform'] ?? '')),
                    ':key_hash' => trim((string) ($record['key_hash'] ?? '')),
                    ':created_at' => trim((string) ($record['created_at'] ?? gmdate(DATE_ATOM))),
                    ':updated_at' => trim((string) ($record['updated_at'] ?? gmdate(DATE_ATOM))),
                    ':expires_at' => $this->nullableText($record['expires_at'] ?? null),
                    ':used_at' => $this->nullableText($record['used_at'] ?? null),
                    ':used_by_device_id' => $this->nullableText($record['used_by_device_id'] ?? null),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readEnrollmentRecords(): array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` ORDER BY `created_at` DESC, `enrollment_id` DESC',
            $this->enrollmentsTable
        );
        $rows = $this->pdo->query($sql)->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $records[] = [
                'enrollment_id' => (string) ($row['enrollment_id'] ?? ''),
                'platform' => (string) ($row['platform'] ?? ''),
                'key_hash' => (string) ($row['key_hash'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'expires_at' => $this->nullableText($row['expires_at'] ?? null),
                'used_at' => $this->nullableText($row['used_at'] ?? null),
                'used_by_device_id' => $this->nullableText($row['used_by_device_id'] ?? null),
            ];
        }

        return $records;
    }

    private function writeAdminUsersRecords(array $data): void
    {
        $rawUsers = is_array($data['users'] ?? null) ? $data['users'] : [];
        $users = [];
        foreach ($rawUsers as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (!isset($value['email']) && is_string($key) && trim($key) !== '') {
                $value['email'] = $key;
            }
            $users[] = $value;
        }

        $deleteSql = sprintf('DELETE FROM `%s`', $this->adminUsersTable);
        $insertSql = sprintf(
            'INSERT INTO `%s`
                (`email`, `name`, `role`, `is_active`, `created_at`, `updated_at`, `created_by`, `updated_by`)
             VALUES
                (:email, :name, :role, :is_active, :created_at, :updated_at, :created_by, :updated_by)',
            $this->adminUsersTable
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($deleteSql);
            $statement = $this->pdo->prepare($insertSql);

            foreach ($users as $record) {
                $email = strtolower(trim((string) ($record['email'] ?? '')));
                if ($email === '') {
                    continue;
                }

                $statement->execute([
                    ':email' => $email,
                    ':name' => trim((string) ($record['name'] ?? '')),
                    ':role' => trim((string) ($record['role'] ?? 'technician')),
                    ':is_active' => (bool) ($record['active'] ?? true) ? 1 : 0,
                    ':created_at' => trim((string) ($record['created_at'] ?? gmdate(DATE_ATOM))),
                    ':updated_at' => trim((string) ($record['updated_at'] ?? gmdate(DATE_ATOM))),
                    ':created_by' => trim((string) ($record['created_by'] ?? 'system')),
                    ':updated_by' => trim((string) ($record['updated_by'] ?? 'system')),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function readAdminUsersMap(): array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` ORDER BY `email` ASC',
            $this->adminUsersTable
        );
        $rows = $this->pdo->query($sql)->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $users = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $users[$email] = [
                'email' => $email,
                'name' => (string) ($row['name'] ?? ''),
                'role' => (string) ($row['role'] ?? 'technician'),
                'active' => ((int) ($row['is_active'] ?? 1)) === 1,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'created_by' => (string) ($row['created_by'] ?? 'system'),
                'updated_by' => (string) ($row['updated_by'] ?? 'system'),
            ];
        }

        return $users;
    }

    private function writeAdminPasskeysRecords(array $data): void
    {
        $rawUsers = is_array($data['users'] ?? null) ? $data['users'] : [];

        $deleteSql = sprintf('DELETE FROM `%s`', $this->adminPasskeysTable);
        $insertSql = sprintf(
            'INSERT INTO `%s`
                (`user_email`, `credential_id`, `name`, `public_key_pem`, `counter_value`, `transports_json`, `created_at`, `updated_at`, `last_used_at`)
             VALUES
                (:user_email, :credential_id, :name, :public_key_pem, :counter_value, :transports_json, :created_at, :updated_at, :last_used_at)',
            $this->adminPasskeysTable
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($deleteSql);
            $statement = $this->pdo->prepare($insertSql);

            foreach ($rawUsers as $emailKey => $userRecord) {
                if (!is_array($userRecord)) {
                    continue;
                }

                $email = is_string($emailKey) && trim($emailKey) !== ''
                    ? strtolower(trim($emailKey))
                    : strtolower(trim((string) ($userRecord['email'] ?? '')));
                if ($email === '') {
                    continue;
                }

                $credentials = is_array($userRecord['credentials'] ?? null) ? $userRecord['credentials'] : [];
                foreach ($credentials as $credential) {
                    if (!is_array($credential)) {
                        continue;
                    }

                    $credentialId = trim((string) ($credential['credential_id'] ?? ''));
                    if ($credentialId === '') {
                        continue;
                    }

                    $statement->execute([
                        ':user_email' => $email,
                        ':credential_id' => $credentialId,
                        ':name' => trim((string) ($credential['name'] ?? 'Passkey')),
                        ':public_key_pem' => trim((string) ($credential['public_key_pem'] ?? '')),
                        ':counter_value' => max(0, (int) ($credential['counter'] ?? 0)),
                        ':transports_json' => Json::encode(
                            is_array($credential['transports'] ?? null) ? $credential['transports'] : []
                        ),
                        ':created_at' => trim((string) ($credential['created_at'] ?? gmdate(DATE_ATOM))),
                        ':updated_at' => trim((string) ($credential['updated_at'] ?? gmdate(DATE_ATOM))),
                        ':last_used_at' => $this->nullableText($credential['last_used_at'] ?? null),
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function readAdminPasskeysMap(): array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` ORDER BY `user_email` ASC, `updated_at` DESC',
            $this->adminPasskeysTable
        );
        $rows = $this->pdo->query($sql)->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $users = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $email = strtolower(trim((string) ($row['user_email'] ?? '')));
            if ($email === '') {
                continue;
            }

            if (!isset($users[$email])) {
                $users[$email] = [
                    'credentials' => [],
                ];
            }

            $users[$email]['credentials'][] = [
                'credential_id' => (string) ($row['credential_id'] ?? ''),
                'name' => (string) ($row['name'] ?? 'Passkey'),
                'public_key_pem' => (string) ($row['public_key_pem'] ?? ''),
                'counter' => max(0, (int) ($row['counter_value'] ?? 0)),
                'transports' => $this->decodeJsonArray((string) ($row['transports_json'] ?? '[]')),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'last_used_at' => $this->nullableText($row['last_used_at'] ?? null),
            ];
        }

        return $users;
    }

    private function writeAutomationProfiles(array $data): void
    {
        $profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];

        $deleteSql = sprintf('DELETE FROM `%s`', $this->automationsTable);
        $insertSql = sprintf(
            'INSERT INTO `%s`
                (`profile_id`, `name`, `is_active`, `run_on_new_agents`, `next_execution_at`, `updated_at`, `payload_json`)
             VALUES
                (:profile_id, :name, :is_active, :run_on_new_agents, :next_execution_at, :updated_at, :payload_json)',
            $this->automationsTable
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($deleteSql);
            $statement = $this->pdo->prepare($insertSql);

            foreach ($profiles as $profile) {
                if (!is_array($profile)) {
                    continue;
                }

                $profileId = trim((string) ($profile['profile_id'] ?? ''));
                if ($profileId === '') {
                    continue;
                }

                $statement->execute([
                    ':profile_id' => $profileId,
                    ':name' => trim((string) ($profile['name'] ?? 'Automation Profile')),
                    ':is_active' => (bool) ($profile['active'] ?? true) ? 1 : 0,
                    ':run_on_new_agents' => (bool) ($profile['run_on_new_agents'] ?? false) ? 1 : 0,
                    ':next_execution_at' => $this->nullableText($profile['next_execution_at'] ?? null),
                    ':updated_at' => trim((string) ($profile['updated_at'] ?? gmdate(DATE_ATOM))),
                    ':payload_json' => Json::encode($profile),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readAutomationProfiles(): array
    {
        $sql = sprintf(
            'SELECT `payload_json` FROM `%s` ORDER BY `updated_at` DESC, `profile_id` ASC',
            $this->automationsTable
        );
        $rows = $this->pdo->query($sql)->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $profile = Json::decodeObject((string) ($row['payload_json'] ?? '{}'));
            if (!is_array($profile)) {
                continue;
            }
            $profiles[] = $profile;
        }

        return $profiles;
    }

    private function writeInventorySnapshot(string $agentRecordId, array $snapshot): void
    {
        $sql = sprintf(
            'INSERT INTO `%s` (`agent_record_id`, `stored_at`, `snapshot_json`)
             VALUES (:agent_record_id, :stored_at, :snapshot_json)
             ON DUPLICATE KEY UPDATE `stored_at` = VALUES(`stored_at`), `snapshot_json` = VALUES(`snapshot_json`)',
            $this->inventoryTable
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':agent_record_id' => trim($agentRecordId),
            ':stored_at' => trim((string) ($snapshot['stored_at'] ?? gmdate(DATE_ATOM))),
            ':snapshot_json' => Json::encode($snapshot),
        ]);
    }

    private function readInventorySnapshot(string $agentRecordId): ?array
    {
        $sql = sprintf(
            'SELECT `snapshot_json` FROM `%s` WHERE `agent_record_id` = :agent_record_id LIMIT 1',
            $this->inventoryTable
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':agent_record_id' => trim($agentRecordId),
        ]);
        $value = $statement->fetchColumn();
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = Json::decodeObject($value);
        return is_array($decoded) ? $decoded : null;
    }

    private function replaceEventsFromNdjson(string $agentRecordId, string $content): void
    {
        $deleteSql = sprintf('DELETE FROM `%s` WHERE `agent_record_id` = :agent_record_id', $this->eventsTable);
        $insertSql = sprintf(
            'INSERT INTO `%s` (`agent_record_id`, `device_id`, `recorded_at`, `event_json`)
             VALUES (:agent_record_id, :device_id, :recorded_at, :event_json)',
            $this->eventsTable
        );

        $agentRecordId = trim($agentRecordId);
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        $this->pdo->beginTransaction();
        try {
            $deleteStatement = $this->pdo->prepare($deleteSql);
            $deleteStatement->execute([':agent_record_id' => $agentRecordId]);

            $insertStatement = $this->pdo->prepare($insertSql);
            foreach ($lines as $line) {
                $trimmed = trim((string) $line);
                if ($trimmed === '') {
                    continue;
                }

                $entry = Json::decodeObject($trimmed);
                if (!is_array($entry)) {
                    continue;
                }

                $event = is_array($entry['event'] ?? null) ? $entry['event'] : [];
                $insertStatement->execute([
                    ':agent_record_id' => $agentRecordId,
                    ':device_id' => trim((string) ($entry['device_id'] ?? 'unknown-device')),
                    ':recorded_at' => trim((string) ($entry['recorded_at'] ?? gmdate(DATE_ATOM))),
                    ':event_json' => Json::encode($event),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function readRawDocument(string $path): ?string
    {
        $sql = sprintf(
            'SELECT `content` FROM `%s` WHERE `path` = :path LIMIT 1',
            $this->documentsTable
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':path' => $path]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private function writeRawDocument(string $path, string $content): void
    {
        $sql = sprintf(
            'INSERT INTO `%s` (`path`, `content`, `created_at`, `updated_at`)
             VALUES (:path, :content, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE `content` = VALUES(`content`), `updated_at` = CURRENT_TIMESTAMP(6)',
            $this->documentsTable
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':path' => $path,
            ':content' => $content,
        ]);
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `path` VARCHAR(255) NOT NULL,
                `content` LONGTEXT NOT NULL,
                `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`path`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->documentsTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `agent_record_id` VARCHAR(64) NOT NULL,
                `device_id` VARCHAR(255) NOT NULL,
                `hostname` VARCHAR(255) NOT NULL,
                `display_name` VARCHAR(255) NOT NULL,
                `domain_name` VARCHAR(255) NOT NULL,
                `os_json` LONGTEXT NOT NULL,
                `agent_json` LONGTEXT NOT NULL,
                `capabilities_json` LONGTEXT NOT NULL,
                `token_hash` VARCHAR(128) NOT NULL,
                `created_at` VARCHAR(40) NOT NULL,
                `updated_at` VARCHAR(40) NOT NULL,
                `last_seen_at` VARCHAR(40) NULL,
                `last_heartbeat_json` LONGTEXT NOT NULL,
                PRIMARY KEY (`agent_record_id`),
                UNIQUE KEY `uniq_agents_device_id` (`device_id`),
                KEY `idx_agents_last_seen_at` (`last_seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->agentsTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `job_id` VARCHAR(64) NOT NULL,
                `seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `position_index` INT NOT NULL DEFAULT 0,
                `type` VARCHAR(128) NOT NULL,
                `correlation_id` VARCHAR(255) NOT NULL,
                `status` VARCHAR(64) NOT NULL,
                `target_agent_id` VARCHAR(128) NOT NULL,
                `target_device_id` VARCHAR(255) NOT NULL,
                `created_at` VARCHAR(40) NOT NULL,
                `updated_at` VARCHAR(40) NULL,
                `acknowledged_at` VARCHAR(40) NULL,
                `completed_at` VARCHAR(40) NULL,
                `canceled_at` VARCHAR(40) NULL,
                `record_json` LONGTEXT NOT NULL,
                PRIMARY KEY (`job_id`),
                UNIQUE KEY `uniq_jobs_seq` (`seq`),
                KEY `idx_jobs_status` (`status`),
                KEY `idx_jobs_target_agent` (`target_agent_id`),
                KEY `idx_jobs_target_device` (`target_device_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->jobsTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `enrollment_id` VARCHAR(64) NOT NULL,
                `platform` VARCHAR(32) NOT NULL,
                `key_hash` VARCHAR(128) NOT NULL,
                `created_at` VARCHAR(40) NOT NULL,
                `updated_at` VARCHAR(40) NOT NULL,
                `expires_at` VARCHAR(40) NULL,
                `used_at` VARCHAR(40) NULL,
                `used_by_device_id` VARCHAR(255) NULL,
                PRIMARY KEY (`enrollment_id`),
                UNIQUE KEY `uniq_enrollments_key_hash` (`key_hash`),
                KEY `idx_enrollments_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->enrollmentsTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `agent_record_id` VARCHAR(64) NOT NULL,
                `stored_at` VARCHAR(40) NOT NULL,
                `snapshot_json` LONGTEXT NOT NULL,
                PRIMARY KEY (`agent_record_id`),
                KEY `idx_inventory_stored_at` (`stored_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->inventoryTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agent_record_id` VARCHAR(64) NOT NULL,
                `device_id` VARCHAR(255) NOT NULL,
                `recorded_at` VARCHAR(40) NOT NULL,
                `event_json` LONGTEXT NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `idx_events_agent_recorded` (`agent_record_id`, `recorded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->eventsTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `email` VARCHAR(255) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `role` VARCHAR(32) NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` VARCHAR(40) NOT NULL,
                `updated_at` VARCHAR(40) NOT NULL,
                `created_by` VARCHAR(255) NOT NULL,
                `updated_by` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`email`),
                KEY `idx_admin_users_role` (`role`),
                KEY `idx_admin_users_is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->adminUsersTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `user_email` VARCHAR(255) NOT NULL,
                `credential_id` VARCHAR(255) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `public_key_pem` LONGTEXT NOT NULL,
                `counter_value` INT UNSIGNED NOT NULL DEFAULT 0,
                `transports_json` LONGTEXT NOT NULL,
                `created_at` VARCHAR(40) NOT NULL,
                `updated_at` VARCHAR(40) NOT NULL,
                `last_used_at` VARCHAR(40) NULL,
                PRIMARY KEY (`user_email`, `credential_id`),
                KEY `idx_admin_passkeys_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->adminPasskeysTable
        ));

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `profile_id` VARCHAR(64) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `run_on_new_agents` TINYINT(1) NOT NULL DEFAULT 0,
                `next_execution_at` VARCHAR(40) NULL,
                `updated_at` VARCHAR(40) NOT NULL,
                `payload_json` LONGTEXT NOT NULL,
                PRIMARY KEY (`profile_id`),
                KEY `idx_automations_next_execution` (`next_execution_at`),
                KEY `idx_automations_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->automationsTable
        ));
    }

    private function decodeJsonArray(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $decoded = Json::decodeObject($content);
        return is_array($decoded) ? $decoded : [];
    }

    private function tableHasRows(string $table): bool
    {
        $sql = sprintf('SELECT 1 FROM `%s` LIMIT 1', $table);
        $statement = $this->pdo->query($sql);
        return $statement !== false && $statement->fetchColumn() !== false;
    }

    private function normalizePath(string $relativePath): string
    {
        $path = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($path === '') {
            throw new \RuntimeException('Storage path must not be empty.');
        }

        if (strlen($path) > 255) {
            throw new \RuntimeException('Storage path is too long for MySQL document key.');
        }

        return $path;
    }

    private function nullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}

