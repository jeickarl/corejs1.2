import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';

export class CashMovementDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ enum: ['income', 'expense'] })
  type!: 'income' | 'expense';

  @ApiProperty({ example: 1 })
  cashSessionId!: number;

  @ApiProperty({ example: 10000 })
  amount!: number;

  @ApiPropertyOptional({ example: 'Efectivo', nullable: true })
  paymentMethod!: string | null;

  @ApiPropertyOptional({ example: 'Pago de factura FAC-00001', nullable: true })
  concept!: string | null;

  @ApiPropertyOptional({ example: 'REF123', nullable: true })
  referenceNumber!: string | null;

  @ApiPropertyOptional({ example: '', nullable: true })
  notes!: string | null;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;

  @ApiPropertyOptional({ example: 1, nullable: true })
  createdBy!: number | null;
}

