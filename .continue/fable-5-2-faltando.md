# Claude Fable 5.2 — o que ainda falta publicar

> **Pedido do Samir (2026-11-19):** "deixa documentado que esta faltando o fable, depois eu passo
> mais runs, agora vamos parar as runs". Nenhuma run do Fable está em andamento.
>
> **Sai desta fila** quando as cinco runs do Fable 5.2 na LEB-S-300-A estiverem em `results/` (ou
> quando o Samir decidir fechar o caso com menos), no commit que carrega isso e não antes.

---

## Onde o Claude Fable 5.2 (`claude-fable-5-2`, esforço `xhigh`) está

| LEB | O caso pede | Publicado hoje | Falta |
|---|---|---|---|
| LEB-D-300-A | 5 runs | 5: 880, 619 (orçamento esgotado, sem relatório), 861, 834 e 856. Nota 858, Gold, 3º de 60 | nada |
| LEB-100-A | 3 runs | 3: 847, 843 e 830 | nada |
| LEB-300-A | 3 runs | 3: 947, 946 e 896. Nota oficial 946, Platinum, 1º de 42 | nada |
| **LEB-S-300-A** | **5 runs** | **3: 968, 995 e 995. Média 986, Platinum, 8º de 41** | **as runs 4 e 5** |

## O que falta no S

- **Run 4: terminou bloqueada e não foi pontuada.** A sessão acabou com recusa do provedor
  (`stop_reason: refusal`, `terminal_reason: api_error`) depois de 242 turnos e 57 minutos, a
  US$ 20,32 de preço de tabela do cliente. O procedimento do S pontua uma run bloqueada sobre a pasta
  que ela deixou (desfecho `blocked`): quatro juízes cegos (dois para os itens plantados, dois para o
  relatório) e o arquivamento. Enquanto ela não for pontuada, a configuração inteira fica de fora da
  publicação, porque uma run sem pontuar segura a configuração toda. Se o Samir preferir refazer a run
  em vez de pontuar a pasta, a decisão é dele.
- **Run 5: 994, julgada e fechada, ainda não publicada,** pelo mesmo motivo (a run 4 segura a
  configuração).
- **Mais runs:** o Samir disse que passa depois. O Fable roda na assinatura pessoal dele, sem janela
  de 5 horas e só com a de 7 dias (zera na terça às 14:00), então cada run consome uma fatia dela. As
  do S saíram a cerca de US$ 19 a 20 de preço de tabela e 1 hora cada.

## Como retomar

1. Pontuar a run 4 do S sobre a pasta que ela deixou e arquivar a configuração inteira (runs 1 a 5).
2. Publicar pelo procedimento do S (agregado, leitura, parágrafo de resultados do README da instância)
   e sincronizar o site.
