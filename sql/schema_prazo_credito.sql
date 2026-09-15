-- Prazo de pagamento (em dias) que o lojista da a cada cliente pra pagar uma
-- compra na Linha de Credito -- livre por cliente (ex: 15 ou 30 dias). Usado
-- pra calcular, a qualquer momento, se uma compra ja venceu (data da compra
-- + esse prazo < hoje). Default 30 dias pra quem ja tem divida nao ficar
-- sem prazo nenhum configurado.
ALTER TABLE clientes ADD COLUMN prazo_dias_credito INT NOT NULL DEFAULT 30;
