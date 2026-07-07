<?php
/*
Retry automático com backoff para deadlocks
A ideia central: deadlock não é um bug no seu código — é uma consequência esperada de concorrência real. O MySQL detecta o ciclo de espera e mata uma das transactions de propósito, como forma de resolver o impasse. A resposta correta não é "consertar" nada, é tentar de novo, porque na segunda tentativa os locks provavelmente já foram liberados pela outra transaction que completou.

*/

function executarComRetry(PDO $pdo, callable $operacao, int $maxTentativas = 3)
{
    $tentativa = 0;

    while (true) {
        $tentativa++;

        try {
            return $operacao($pdo);

        } catch (PDOException $e) {
            $isDeadlock = $e->errorInfo[1] === 1213;      // Deadlock found
            $isLockTimeout = $e->errorInfo[1] === 1205;   // Lock wait timeout

            if (!$isDeadlock && !$isLockTimeout) {
                throw $e; // erro real (sintaxe, constraint, etc) — não adianta tentar de novo
            }

            if ($tentativa >= $maxTentativas) {
                throw new RuntimeException(
                    "Falhou após {$maxTentativas} tentativas (deadlock persistente)",
                    0,
                    $e
                );
            }

            // Backoff: espera um pouco antes de tentar de novo,
            // com jitter (aleatoriedade) pra evitar que os mesmos
            // processos colidam de novo na mesma janela de tempo
            $esperaMs = (100 * $tentativa) + random_int(0, 100);
            usleep($esperaMs * 1000);

            // segue o loop e tenta de novo
        }
    }
}

// Uso com o cenário do pedido
$pedidoId = executarComRetry($pdo, function (PDO $pdo) use ($itens) {
    return process_order($pdo, $itens);
});

/*
Repare que processarPedido() não mudou nada — ela continua abrindo e fechando sua própria transaction normalmente. O executarComRetry() só a envolve por fora, chamando ela de novo do zero se o erro for especificamente de lock.
Pontos importantes
Por que checar errorInfo[1] especificamente: PDOException genérica pode ser qualquer coisa — chave duplicada, coluna inexistente, tipo errado. Se você fizer retry cego em qualquer exception, um erro de sintaxe SQL vai ficar tentando 3 vezes inutilmente (e mascarando o erro real). Os códigos 1213 e 1205 são específicos do MySQL pra "não consegui pegar o lock", que são os únicos casos onde retry faz sentido.
Por que backoff crescente (100 * $tentativa): se dois processos colidem e ambos fazem retry imediato, é bem provável que colidam de novo instantaneamente — mesma corrida, mesmo resultado. Esperar um pouco mais a cada tentativa reduz a chance de sincronização acidental.
Por que o jitter (random_int(0, 100)): sem isso, se dois processos entraram em deadlock exatamente no mesmo instante, os dois vão esperar exatamente o mesmo tempo e colidir de novo na mesma hora. O jitter quebra esse sincronismo.
Limite de tentativas: importante ter um teto. Deadlock recorrente sem parar geralmente indica um problema estrutural (ex: ordem de lock inconsistente entre partes do código) que retry não resolve — nesse ponto o erro deveria subir e ser investigado, não ficar tentando pra sempre.
Uma correção estrutural que evita boa parte do problema
Vale mencionar: o jeito mais robusto de evitar deadlock não é só ter retry — é padronizar a ordem de lock. No cenário do pedido, isso significa ordenar os itens por produto_id antes do loop:
*/

usort($itens, fn($a, $b) => $a['produto_id'] <=> $b['produto_id']);

/*
Se todo pedido sempre trava os produtos em ordem crescente de ID, dois pedidos concorrentes nunca vão formar um ciclo de espera — um deles simplesmente espera o outro terminar, sem deadlock algum. O retry com backoff continua sendo uma boa prática de defesa, mas essa mudança reduz drasticamente a frequência com que ele é necessário.
*/