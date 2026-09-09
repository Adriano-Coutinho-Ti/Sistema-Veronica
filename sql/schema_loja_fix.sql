-- Índice pra varredura de reservas expiradas (liberarReservasExpiradas() em includes/loja.php),
-- que filtra por origem + status + data_venda em toda página/endpoint da loja.
ALTER TABLE vendas ADD INDEX idx_loja_expira (origem, status, data_venda);
