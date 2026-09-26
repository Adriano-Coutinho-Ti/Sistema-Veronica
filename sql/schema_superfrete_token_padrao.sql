-- Token padrão de CONSULTA da SuperFrete (conta só para cotação, sem saldo),
-- já vem preenchido para o dev não precisar criar conta. O dev pode trocar
-- pelo painel_dev. Só preenche se ainda estiver vazio.
UPDATE config_dev SET superfrete_token_dev = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpYXQiOjE3NjMwNTcyNDksInN1YiI6Im9zdDVHRUlMbXBkdkhITVFobGlFUnZXejNQODMifQ.U9bWTx6l3SrvdGxGT1LIdl0KWiCULS9mqUOeW605w6A'
WHERE id_config = 1 AND (superfrete_token_dev IS NULL OR superfrete_token_dev = '');
