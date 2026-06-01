import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';

export class CashSessionDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ enum: ['open', 'closed'] })
  status!: 'open' | 'closed';

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  openingDate!: string;

  @ApiPropertyOptional({ example: '2026-01-01 18:00:00', nullable: true })
  closingDate!: string | null;

  @ApiPropertyOptional({ example: 1, nullable: true })
  openedBy!: number | null;

  @ApiPropertyOptional({ example: 1, nullable: true })
  closedBy!: number | null;

  @ApiProperty({ example: 0 })
  initialAmount!: number;

  @ApiPropertyOptional({ example: 0, nullable: true })
  finalAmount!: number | null;

  @ApiProperty({ example: 0 })
  systemTotal!: number;

  @ApiPropertyOptional({ example: 0, nullable: true })
  physicalCount!: number | null;

  @ApiPropertyOptional({ example: 0, nullable: true })
  difference!: number | null;
}

