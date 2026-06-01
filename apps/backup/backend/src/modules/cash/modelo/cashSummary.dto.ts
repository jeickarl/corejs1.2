import { ApiProperty } from '@nestjs/swagger';

export class CashSummaryDto {
  @ApiProperty({ example: 0 })
  totalIncome!: number;

  @ApiProperty({ example: 0 })
  totalExpense!: number;

  @ApiProperty({ example: 0 })
  systemTotal!: number;
}

