import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

function asMoney(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

export type CashSessionRow = {
  id: number;
  status: 'open' | 'closed';
  openingDate: string;
  closingDate: string | null;
  openedBy: number | null;
  closedBy: number | null;
  initialAmount: number;
  finalAmount: number | null;
  systemTotal: number;
  physicalCount: number | null;
  difference: number | null;
};

export type CashMovementRow = {
  id: number;
  type: 'income' | 'expense';
  cashSessionId: number;
  amount: number;
  paymentMethod: string | null;
  concept: string | null;
  referenceNumber: string | null;
  notes: string | null;
  createdAt: string;
  createdBy: number | null;
};

@Injectable()
export class CashDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS cash_sessions (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          opening_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          closing_date DATETIME NULL,
          opened_by INT(10) UNSIGNED NULL,
          closed_by INT(10) UNSIGNED NULL,
          initial_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          final_amount DECIMAL(12,2) NULL,
          status ENUM('open','closed') NOT NULL DEFAULT 'open',
          total_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          total_transfer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          total_card DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          total_other DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          system_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          physical_count DECIMAL(12,2) NULL,
          difference DECIMAL(12,2) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS cash_income (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          cash_session_id INT(10) UNSIGNED NOT NULL,
          income_type VARCHAR(50) NULL,
          concept_id INT(11) NULL,
          concept VARCHAR(255) NULL,
          amount DECIMAL(12,2) NOT NULL,
          payment_method VARCHAR(100) NULL,
          reference_number VARCHAR(100) NULL,
          notes TEXT NULL,
          description TEXT NULL,
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          payment_account_id INT(11) NULL,
          KEY idx_cash_session_id (cash_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS cash_expenses (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          cash_session_id INT(10) UNSIGNED NOT NULL,
          category_id INT(10) UNSIGNED NULL,
          concept VARCHAR(255) NULL,
          amount DECIMAL(12,2) NOT NULL,
          receipt_image VARCHAR(255) NULL,
          notes TEXT NULL,
          reference_number VARCHAR(100) NULL,
          created_by INT(10) UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          KEY idx_cash_session_id (cash_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }
  }

  async getOpenSession(input: { empresaId: number }): Promise<CashSessionRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT *
        FROM cash_sessions
        WHERE status = 'open'
        ORDER BY opening_date DESC
        LIMIT 1
        `,
      );
      const r = rows?.[0];
      if (!r) return null;
      return {
        id: Number(r.id),
        status: 'open',
        openingDate: String(r.opening_date ?? ''),
        closingDate: r.closing_date === undefined || r.closing_date === null ? null : String(r.closing_date),
        openedBy: r.opened_by === undefined || r.opened_by === null ? null : Number(r.opened_by),
        closedBy: r.closed_by === undefined || r.closed_by === null ? null : Number(r.closed_by),
        initialAmount: asMoney(r.initial_amount),
        finalAmount: r.final_amount === undefined || r.final_amount === null ? null : asMoney(r.final_amount),
        systemTotal: asMoney(r.system_total),
        physicalCount: r.physical_count === undefined || r.physical_count === null ? null : asMoney(r.physical_count),
        difference: r.difference === undefined || r.difference === null ? null : asMoney(r.difference),
      };
    } finally {
      await conn.end();
    }
  }

  async openSession(input: { empresaId: number; openedBy: number | null; initialAmount: number }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const existing = await this.getOpenSession({ empresaId: input.empresaId });
      if (existing) return existing.id;
      const [result] = await conn.execute(
        `
        INSERT INTO cash_sessions (opening_date, opened_by, initial_amount, status, system_total)
        VALUES (NOW(), ?, ?, 'open', ?)
        `,
        [input.openedBy, asMoney(input.initialAmount), asMoney(input.initialAmount)],
      );
      const anyResult = result as { insertId?: number };
      return Number(anyResult.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async computeSessionTotals(input: { empresaId: number; cashSessionId: number }): Promise<{
    totalIncome: number;
    totalExpense: number;
    systemTotal: number;
  }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [sessRows] = await conn.query<RowDataPacket[]>(
        'SELECT initial_amount FROM cash_sessions WHERE id = ? LIMIT 1',
        [input.cashSessionId],
      );
      const initial = asMoney(sessRows?.[0]?.initial_amount ?? 0);
      const [incRows] = await conn.query<RowDataPacket[]>(
        'SELECT COALESCE(SUM(amount),0) as s FROM cash_income WHERE cash_session_id = ?',
        [input.cashSessionId],
      );
      const [expRows] = await conn.query<RowDataPacket[]>(
        'SELECT COALESCE(SUM(amount),0) as s FROM cash_expenses WHERE cash_session_id = ?',
        [input.cashSessionId],
      );
      const totalIncome = asMoney(incRows?.[0]?.s ?? 0);
      const totalExpense = asMoney(expRows?.[0]?.s ?? 0);
      const systemTotal = asMoney(initial + totalIncome - totalExpense);
      return { totalIncome, totalExpense, systemTotal };
    } finally {
      await conn.end();
    }
  }

  async closeSession(input: {
    empresaId: number;
    closedBy: number | null;
    finalAmount: number;
    physicalCount?: number | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const open = await this.getOpenSession({ empresaId: input.empresaId });
      if (!open) return false;
      const totals = await this.computeSessionTotals({ empresaId: input.empresaId, cashSessionId: open.id });
      const physical = input.physicalCount === undefined ? null : input.physicalCount;
      const finalAmount = asMoney(input.finalAmount);
      const diff = asMoney((physical ?? finalAmount) - totals.systemTotal);
      const [result] = await conn.execute(
        `
        UPDATE cash_sessions
        SET status = 'closed',
            closing_date = NOW(),
            closed_by = ?,
            final_amount = ?,
            system_total = ?,
            physical_count = ?,
            difference = ?
        WHERE id = ? AND status = 'open'
        `,
        [input.closedBy, finalAmount, totals.systemTotal, physical, diff, open.id],
      );
      const anyResult = result as { affectedRows?: number };
      return Number(anyResult.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async movements(input: { empresaId: number; cashSessionId: number; limit: number }): Promise<CashMovementRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const limit = Math.min(Math.max(1, input.limit), 500);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        (
          SELECT id, 'income' as type, cash_session_id, amount, payment_method, concept, reference_number, notes, created_at, created_by
          FROM cash_income
          WHERE cash_session_id = ?
        )
        UNION ALL
        (
          SELECT id, 'expense' as type, cash_session_id, amount, NULL as payment_method, concept, reference_number, notes, created_at, created_by
          FROM cash_expenses
          WHERE cash_session_id = ?
        )
        ORDER BY created_at DESC
        LIMIT ?
        `,
        [input.cashSessionId, input.cashSessionId, limit],
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        type: String(r.type ?? 'income') as 'income' | 'expense',
        cashSessionId: Number(r.cash_session_id ?? 0),
        amount: asMoney(r.amount),
        paymentMethod: r.payment_method === undefined || r.payment_method === null ? null : String(r.payment_method),
        concept: r.concept === undefined || r.concept === null ? null : String(r.concept),
        referenceNumber: r.reference_number === undefined || r.reference_number === null ? null : String(r.reference_number),
        notes: r.notes === undefined || r.notes === null ? null : String(r.notes),
        createdAt: String(r.created_at ?? ''),
        createdBy: r.created_by === undefined || r.created_by === null ? null : Number(r.created_by),
      }));
    } finally {
      await conn.end();
    }
  }
}

