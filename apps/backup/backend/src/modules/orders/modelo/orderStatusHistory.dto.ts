import { ApiProperty } from '@nestjs/swagger';

export class OrderStatusHistoryDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'pending' })
  status!: string;

  @ApiProperty({ example: 1, nullable: true })
  userId!: number | null;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;
}

