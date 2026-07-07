<?php

$dot_env = Dotenv\Dotenv::createImmutable(__DIR__);
$dot_env->load();

function get_pdo(): \PDO
{
    $host = getenv("DB_HOST");
    $dbname = getenv("DB_NAME");
    $charset = getenv("DB_CHARSET");

    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

    $user = getenv("DB_USER");
    $pass = getenv("DB_PASS");
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];

    return new PDO($dsn, $user, $pass, $options);
}

function transfer(PDO $pdo, int $origem_id, int $destino_id, float $valor): void
{
    $pdo->beginTransaction();

    try {
        // 1. Verifica saldo (com lock para evitar race condition)
        $stmt = $pdo->prepare('SELECT saldo FROM contas WHERE id = ? FOR UPDATE');
        $stmt->execute([$origem_id]);
        $saldo = $stmt->fetchColumn();

        if ($saldo < $valor) {
            throw new RuntimeException('Saldo insuficiente');
        }

        // 2. Debita origem
        $stmt = $pdo->prepare('UPDATE contas SET saldo = saldo - ? WHERE id = ?');
        $stmt->execute([$valor, $origem_id]);

        // 3. Credita destino
        $stmt = $pdo->prepare('UPDATE contas SET saldo = saldo + ? WHERE id = ?');
        $stmt->execute([$valor, $destino_id]);

        // 4. Registra a transação
        $stmt = $pdo->prepare(
            'INSERT INTO transacoes (conta_origem_id, conta_destino_id, valor) VALUES (?, ?, ?)'
        );
        $stmt->execute([$origem_id, $destino_id, $valor]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e; // relança pra quem chamou decidir o que fazer
    }
}

$pdo = get_pdo();

transfer($pdo, 1, 2, 200.00);
