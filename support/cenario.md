Por que esse cenário gera deadlock (e o outro não)
No cenário bancário, cada transferência trava só uma linha por vez, sequencialmente (origem, depois destino) — mas sempre na mesma ordem relativa se você padronizar (ex: sempre travar o ID menor primeiro). Aqui, um pedido trava várias linhas de produto ao mesmo tempo, e a ordem em que cada pedido processa os itens pode divergir.
Deadlock clássico:

Pedido A quer Teclado + Monitor → trava Teclado primeiro, depois tenta travar Monitor
Pedido B quer Monitor + Teclado → trava Monitor primeiro, depois tenta travar Teclado

Cada um está esperando o lock que o outro já tem. Nenhum consegue avançar. O MySQL detecta isso e mata uma das duas transactions automaticamente (erro 1213: Deadlock found when trying to get lock).