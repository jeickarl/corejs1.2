import { ApiProperty } from '@nestjs/swagger';

export class InventoryMovementDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 1 })
  productId!: number;

  @ApiProperty({ enum: ['in', 'out', 'adjust'] })
  movementType!: 'in' | 'out' | 'adjust';

  @ApiProperty({ example: 1 })
  quantity!: number;

  @ApiProperty({ example: 'invoice', nullable: true })
  referenceType!: string | null;

  @ApiProperty({ example: 123, nullable: true })
  referenceId!: number | null;

  @ApiProperty({ example: 'Ingreso por compra', nullable: true })
  notes!: string | null;

  @ApiProperty({ example: 1, nullable: true })
  createdBy!: number | null;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;
}

