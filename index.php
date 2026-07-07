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


function process_order(PDO $pdo, array $itens): int
{
    // $itens = [['produto_id' => 1, 'quantidade' => 2], ...]

    $pdo->beginTransaction();

    try {
        $pedidoId = null;
        $stmt = $pdo->prepare('INSERT INTO pedidos (status) VALUES (?)');
        $stmt->execute(['pendente']);
        $pedidoId = $pdo->lastInsertId();

        foreach ($itens as $item) {
            // Trava a linha do produto antes de checar estoque
            $stmt = $pdo->prepare('SELECT estoque FROM produtos WHERE id = ? FOR UPDATE');
            $stmt->execute([$item['produto_id']]);
            $estoque = $stmt->fetchColumn();

            if ($estoque === false) {
                throw new RuntimeException("Produto {$item['produto_id']} não existe");
            }

            if ($estoque < $item['quantidade']) {
                throw new RuntimeException("Estoque insuficiente para produto {$item['produto_id']}");
            }

            $stmt = $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?');
            $stmt->execute([$item['quantidade'], $item['produto_id']]);

            $stmt = $pdo->prepare(
                'INSERT INTO pedido_itens (pedido_id, produto_id, quantidade) VALUES (?, ?, ?)'
            );
            $stmt->execute([$pedidoId, $item['produto_id'], $item['quantidade']]);
        }

        $stmt = $pdo->prepare("UPDATE pedidos SET status = 'confirmado' WHERE id = ?");
        $stmt->execute([$pedidoId]);

        $pdo->commit();
        return $pedidoId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$pdo = get_pdo();

// Praticando rollback (fácil de disparar)
$itens = [
    ['produto_id' => 3, 'quantidade' => 100], // Monitor só tem 5 em estoque
];

try {
    process_order($pdo, $itens);
} catch (RuntimeException $e) {
    echo "Pedido cancelado: " . $e->getMessage();
    // Nenhum produto foi debitado, nenhum pedido_itens foi criado,
    // mas o INSERT em pedidos já rodou antes do throw — e o rollback desfaz ele também.
}


// Praticando deadlock de propósito
// Pra reproduzir o deadlock que expliquei acima, você precisa rodar dois scripts PHP simultaneamente (em dois terminais, ou dois processos), assim:

$itens = [
    ['produto_id' => 1, 'quantidade' => 1], // Teclado primeiro
    ['produto_id' => 3, 'quantidade' => 1], // Monitor depois
];

// Pra garantir que os locks aconteçam quase ao mesmo tempo (senão um termina antes do outro nem travar), você pode inserir um sleep(2) entre os dois FOR UPDATE dentro do loop, só pra fins de teste.
// Um dos dois scripts vai lançar PDOException com código SQLSTATE 40001 (deadlock). O tratamento correto pra isso normalmente é:

try {
    // Script A
    process_order($pdo, $itens);

    // Script B (rodar ao mesmo tempo, em outro processo)
    process_order($pdo, $itens);
} catch (PDOException $e) {
    if ($e->errorInfo[1] === 1213) { // deadlock
        // retry automático é a prática padrão aqui
        // (com um pequeno backoff, ou até 3 tentativas)
    }
    throw $e;
}
