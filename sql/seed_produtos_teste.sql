-- 10 produtos de exemplo pro Brechó da Veve, pra testar a loja com dados
-- reais de vitrine. NÃO é uma migração de schema — é dado de teste, mas
-- segue a mesma regra do projeto (deploy não sobe sql/**, roda manual).
--
-- As fotos (4 por produto, já geradas como placeholder) foram commitadas em
-- assets/img/produtos/9001/ até .../9010/ e sobem pro servidor no próximo
-- push normal (só sql/** fica de fora do deploy). Por isso os produtos abaixo
-- usam ID explícito (9001-9010, faixa alta de propósito pra não colidir com
-- produtos que você já tenha cadastrado) — combinando exatamente com o nome
-- dessas pastas. Se por acaso você já tiver produtos com esses IDs, ajuste
-- os números aqui E renomeie as pastas de foto correspondentes antes de rodar.

INSERT IGNORE INTO categorias (nome) VALUES ('Roupas'), ('Calçados'), ('Acessórios');

INSERT INTO produtos (id_produto, nome, descricao, id_categoria, condicao, preco_base, ativo) VALUES
(9001, 'Jaqueta Jeans Vintage', 'Jaqueta jeans vintage, lavagem clara, tamanho M, em ótimo estado de conservação.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 89.90, 1),
(9002, 'Vestido Floral Midi', 'Vestido floral midi, tecido leve, perfeito pra estações mais quentes.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 64.90, 1),
(9003, 'Tênis Casual Branco', 'Tênis branco casual, poucos usos, solado em ótimo estado.', (SELECT id_categoria FROM categorias WHERE nome = 'Calçados'), 'usado', 120.00, 1),
(9004, 'Bolsa de Couro Caramelo', 'Bolsa de couro legítimo, cor caramelo, alça ajustável.', (SELECT id_categoria FROM categorias WHERE nome = 'Acessórios'), 'usado', 95.00, 1),
(9005, 'Camisa Social Listrada', 'Camisa social listrada azul e branco, tamanho G.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 45.00, 1),
(9006, 'Calça Cargo Verde Militar', 'Calça cargo estilo militar, vários bolsos, tamanho 40.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 70.00, 1),
(9007, 'Blusa de Tricô Bege', 'Blusa de tricô bege, gola redonda, super confortável.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 39.90, 1),
(9008, 'Relógio Analógico Prata', 'Relógio analógico prateado, pulseira de metal, funcionando perfeitamente.', (SELECT id_categoria FROM categorias WHERE nome = 'Acessórios'), 'usado', 55.00, 1),
(9009, 'Óculos de Sol Retrô', 'Óculos de sol estilo retrô, armação redonda, lente com proteção UV.', (SELECT id_categoria FROM categorias WHERE nome = 'Acessórios'), 'novo', 49.90, 1),
(9010, 'Casaco de Lã Cinza', 'Casaco de lã cinza, quentinho, ideal pro inverno.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 110.00, 1);

-- Cada produto com uma única variação (sem tamanho/cor combinando) — preco
-- NULL cai no preco_base do produto (mesma regra usada em todo o sistema).
INSERT INTO produto_variacoes (id_produto_variacao, id_produto, preco, estoque) VALUES
(9001, 9001, NULL, 4),
(9002, 9002, NULL, 3),
(9003, 9003, NULL, 2),
(9004, 9004, NULL, 5),
(9005, 9005, NULL, 6),
(9006, 9006, NULL, 4),
(9007, 9007, NULL, 7),
(9008, 9008, NULL, 3),
(9009, 9009, NULL, 8),
(9010, 9010, NULL, 3);

-- 4 fotos por produto (assets/img/produtos/<id_produto>/<ordem>.png), mesma
-- convenção usada pelo upload de fotos do admin (produtos/ajax/upload_foto.php).
INSERT INTO produto_fotos (id_produto, ordem, caminho_arquivo) VALUES
(9001, 1, 'assets/img/produtos/9001/1.png'), (9001, 2, 'assets/img/produtos/9001/2.png'), (9001, 3, 'assets/img/produtos/9001/3.png'), (9001, 4, 'assets/img/produtos/9001/4.png'),
(9002, 1, 'assets/img/produtos/9002/1.png'), (9002, 2, 'assets/img/produtos/9002/2.png'), (9002, 3, 'assets/img/produtos/9002/3.png'), (9002, 4, 'assets/img/produtos/9002/4.png'),
(9003, 1, 'assets/img/produtos/9003/1.png'), (9003, 2, 'assets/img/produtos/9003/2.png'), (9003, 3, 'assets/img/produtos/9003/3.png'), (9003, 4, 'assets/img/produtos/9003/4.png'),
(9004, 1, 'assets/img/produtos/9004/1.png'), (9004, 2, 'assets/img/produtos/9004/2.png'), (9004, 3, 'assets/img/produtos/9004/3.png'), (9004, 4, 'assets/img/produtos/9004/4.png'),
(9005, 1, 'assets/img/produtos/9005/1.png'), (9005, 2, 'assets/img/produtos/9005/2.png'), (9005, 3, 'assets/img/produtos/9005/3.png'), (9005, 4, 'assets/img/produtos/9005/4.png'),
(9006, 1, 'assets/img/produtos/9006/1.png'), (9006, 2, 'assets/img/produtos/9006/2.png'), (9006, 3, 'assets/img/produtos/9006/3.png'), (9006, 4, 'assets/img/produtos/9006/4.png'),
(9007, 1, 'assets/img/produtos/9007/1.png'), (9007, 2, 'assets/img/produtos/9007/2.png'), (9007, 3, 'assets/img/produtos/9007/3.png'), (9007, 4, 'assets/img/produtos/9007/4.png'),
(9008, 1, 'assets/img/produtos/9008/1.png'), (9008, 2, 'assets/img/produtos/9008/2.png'), (9008, 3, 'assets/img/produtos/9008/3.png'), (9008, 4, 'assets/img/produtos/9008/4.png'),
(9009, 1, 'assets/img/produtos/9009/1.png'), (9009, 2, 'assets/img/produtos/9009/2.png'), (9009, 3, 'assets/img/produtos/9009/3.png'), (9009, 4, 'assets/img/produtos/9009/4.png'),
(9010, 1, 'assets/img/produtos/9010/1.png'), (9010, 2, 'assets/img/produtos/9010/2.png'), (9010, 3, 'assets/img/produtos/9010/3.png'), (9010, 4, 'assets/img/produtos/9010/4.png');
