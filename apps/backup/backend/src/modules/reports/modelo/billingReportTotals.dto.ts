import { ApiProperty } from '@nestjs/swagger';

export class BillingReportTotalsDto {
  @ApiProperty({ example: 100000 })
  totalAmount!: number;

  @ApiProperty({ example: 80000 })
  paidAmount!: number;

  @ApiProperty({ example: 20000 })
  pendingAmount!: number;

  @ApiProperty({ example: 0 })
  cancelledAmount!: number;
}

