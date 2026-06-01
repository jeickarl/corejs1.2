import { ApiProperty } from '@nestjs/swagger';

export class SuppliersReportRowDto {
  @ApiProperty()
  supplierId!: number;

  @ApiProperty()
  supplierName!: string;

  @ApiProperty()
  ordersCount!: number;

  @ApiProperty()
  totalAmount!: number;

  @ApiProperty()
  paidAmount!: number;

  @ApiProperty()
  pendingAmount!: number;
}

