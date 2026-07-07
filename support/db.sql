CREATE TABLE contas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titular VARCHAR(100) NOT NULL,
    saldo DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB; -- InnoDB é obrigatório, MyISAM não suporta transactions

CREATE TABLE transacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_origem_id INT NOT NULL,
    conta_destino_id INT NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conta_origem_id) REFERENCES contas(id),
    FOREIGN KEY (conta_destino_id) REFERENCES contas(id)
) ENGINE=InnoDB;

INSERT INTO contas (titular, saldo) VALUES
('Alice', 1000.00),
('Bob', 500.00);