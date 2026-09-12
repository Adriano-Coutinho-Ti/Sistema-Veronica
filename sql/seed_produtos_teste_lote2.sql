-- Segundo lote de produtos de teste (10 a mais, total 20 com o primeiro
-- lote de sql/seed_produtos_teste.sql) — 5 fotos por produto desta vez.
-- Mesma regra: dado de teste, não é migração de schema, mas segue o padrão
-- de sql/** do projeto (não entra no deploy automático, roda manual).
--
-- IDs 9011-9020, combinando com as fotos em assets/img/produtos/9011 a
-- .../9020 (enviadas por fora do git, igual ao primeiro lote — pasta
-- ignorada porque foto de produto é conteúdo do lojista, não código).

INSERT IGNORE INTO categorias (nome) VALUES ('Roupas'), ('Calçados'), ('Acessórios');

INSERT INTO produtos (id_produto, nome, descricao, id_categoria, condicao, preco_base, ativo) VALUES
(9011, 'Saia Jeans Curta', 'Saia jeans curta, cintura alta, tamanho 38.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 34.90, 1),
(9012, 'Camiseta Estampada Retrô', 'Camiseta estampada estilo retrô, algodão, tamanho M.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 29.90, 1),
(9013, 'Sandália Rasteira de Couro', 'Sandália rasteira de couro legítimo, tamanho 37.', (SELECT id_categoria FROM categorias WHERE nome = 'Calçados'), 'usado', 42.00, 1),
(9014, 'Mochila Impermeável Preta', 'Mochila impermeável preta, compartimento pra notebook.', (SELECT id_categoria FROM categorias WHERE nome = 'Acessórios'), 'usado', 68.00, 1),
(9015, 'Shorts Jeans Destroyed', 'Shorts jeans destroyed, cintura média, tamanho 40.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 32.00, 1),
(9016, 'Cinto de Couro Trançado', 'Cinto de couro trançado, fivela metálica.', (SELECT id_categoria FROM categorias WHERE nome = 'Acessórios'), 'usado', 25.00, 1),
(9017, 'Botas Coturno Preta', 'Botas coturno preta, cadarço, sola resistente.', (SELECT id_categoria FROM categorias WHERE nome = 'Calçados'), 'usado', 135.00, 1),
(9018, 'Cardigã Xadrez Vermelho', 'Cardigã de lã xadrez vermelho e preto, botões frontais.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 48.00, 1),
(9019, 'Boné Aba Reta Preto', 'Boné aba reta preto, ajuste por fivela traseira.', (SELECT id_categoria FROM categorias WHERE nome = 'Acessórios'), 'novo', 22.00, 1),
(9020, 'Vestido de Festa Longo', 'Vestido de festa longo, tecido nobre, usado uma vez.', (SELECT id_categoria FROM categorias WHERE nome = 'Roupas'), 'usado', 150.00, 1);

INSERT INTO produto_variacoes (id_produto_variacao, id_produto, preco, estoque) VALUES
(9011, 9011, NULL, 5),
(9012, 9012, NULL, 9),
(9013, 9013, NULL, 4),
(9014, 9014, NULL, 3),
(9015, 9015, NULL, 6),
(9016, 9016, NULL, 8),
(9017, 9017, NULL, 2),
(9018, 9018, NULL, 5),
(9019, 9019, NULL, 10),
(9020, 9020, NULL, 1);

-- 5 fotos por produto (assets/img/produtos/<id_produto>/<ordem>.png)
INSERT INTO produto_fotos (id_produto, ordem, caminho_arquivo) VALUES
(9011, 1, 'assets/img/produtos/9011/1.png'), (9011, 2, 'assets/img/produtos/9011/2.png'), (9011, 3, 'assets/img/produtos/9011/3.png'), (9011, 4, 'assets/img/produtos/9011/4.png'), (9011, 5, 'assets/img/produtos/9011/5.png'),
(9012, 1, 'assets/img/produtos/9012/1.png'), (9012, 2, 'assets/img/produtos/9012/2.png'), (9012, 3, 'assets/img/produtos/9012/3.png'), (9012, 4, 'assets/img/produtos/9012/4.png'), (9012, 5, 'assets/img/produtos/9012/5.png'),
(9013, 1, 'assets/img/produtos/9013/1.png'), (9013, 2, 'assets/img/produtos/9013/2.png'), (9013, 3, 'assets/img/produtos/9013/3.png'), (9013, 4, 'assets/img/produtos/9013/4.png'), (9013, 5, 'assets/img/produtos/9013/5.png'),
(9014, 1, 'assets/img/produtos/9014/1.png'), (9014, 2, 'assets/img/produtos/9014/2.png'), (9014, 3, 'assets/img/produtos/9014/3.png'), (9014, 4, 'assets/img/produtos/9014/4.png'), (9014, 5, 'assets/img/produtos/9014/5.png'),
(9015, 1, 'assets/img/produtos/9015/1.png'), (9015, 2, 'assets/img/produtos/9015/2.png'), (9015, 3, 'assets/img/produtos/9015/3.png'), (9015, 4, 'assets/img/produtos/9015/4.png'), (9015, 5, 'assets/img/produtos/9015/5.png'),
(9016, 1, 'assets/img/produtos/9016/1.png'), (9016, 2, 'assets/img/produtos/9016/2.png'), (9016, 3, 'assets/img/produtos/9016/3.png'), (9016, 4, 'assets/img/produtos/9016/4.png'), (9016, 5, 'assets/img/produtos/9016/5.png'),
(9017, 1, 'assets/img/produtos/9017/1.png'), (9017, 2, 'assets/img/produtos/9017/2.png'), (9017, 3, 'assets/img/produtos/9017/3.png'), (9017, 4, 'assets/img/produtos/9017/4.png'), (9017, 5, 'assets/img/produtos/9017/5.png'),
(9018, 1, 'assets/img/produtos/9018/1.png'), (9018, 2, 'assets/img/produtos/9018/2.png'), (9018, 3, 'assets/img/produtos/9018/3.png'), (9018, 4, 'assets/img/produtos/9018/4.png'), (9018, 5, 'assets/img/produtos/9018/5.png'),
(9019, 1, 'assets/img/produtos/9019/1.png'), (9019, 2, 'assets/img/produtos/9019/2.png'), (9019, 3, 'assets/img/produtos/9019/3.png'), (9019, 4, 'assets/img/produtos/9019/4.png'), (9019, 5, 'assets/img/produtos/9019/5.png'),
(9020, 1, 'assets/img/produtos/9020/1.png'), (9020, 2, 'assets/img/produtos/9020/2.png'), (9020, 3, 'assets/img/produtos/9020/3.png'), (9020, 4, 'assets/img/produtos/9020/4.png'), (9020, 5, 'assets/img/produtos/9020/5.png');
