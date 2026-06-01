import { ApiProperty } from '@nestjs/swagger';

export class BillingReportRowDto {
  @ApiProperty()
  id!: number;

  @ApiProperty()
  invoiceNumber!: string;

  @ApiProperty()
  clientName!: string;

  @ApiProperty()
  invoiceDate!: string;

  @ApiProperty()
  totalAmount!: number;

  @ApiProperty()
  paidAmount!: number;

  @ApiProperty()
  pendingAmount!: number;

  @ApiProperty()
  paymentStatus!: string;

  @ApiProperty()
  status!: string;
}

