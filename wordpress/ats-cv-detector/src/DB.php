<?php

namespace ATSCV;

final class DB
{
    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix.'atscv_'.$name;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function run(string $sql): int
    {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException('Database operation failed.');
        }

        return $result;
    }

    public static function insert(string $name, array $data): int
    {
        global $wpdb;
        if ($wpdb->insert(self::table($name), $data) === false) {
            throw new \RuntimeException('Database insert failed.');
        }

        return (int) $wpdb->insert_id;
    }

    public static function update(string $name, array $data, array $where): void
    {
        global $wpdb;
        if ($wpdb->update(self::table($name), $data, $where) === false) {
            throw new \RuntimeException('Database update failed.');
        }
    }

    public static function transaction(callable $work): mixed
    {
        self::run('START TRANSACTION');
        try {
            $result = $work();
            self::run('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            self::run('ROLLBACK');
            throw $e;
        }
    }

    public static function lock(int $user): void
    {
        global $wpdb;
        if (! $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $user))) {
            throw new \RuntimeException('Account unavailable.');
        }
    }

    public static function audit(int $user, string $event, string $reference, array $details = []): void
    {
        self::insert('audit', ['user_id' => $user, 'actor_id' => get_current_user_id(), 'event' => $event,
            'reference' => $reference, 'details' => wp_json_encode($details), 'created_at' => self::now()]);
    }
}
