import { ApiProperty } from '@nestjs/swagger';

export class PaymentMethodDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Efectivo' })
  name!: string;

  @ApiProperty({ example: true })
  isDefault!: boolean;

  @ApiProperty({ example: true })
  isActive!: boolean;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;
}

