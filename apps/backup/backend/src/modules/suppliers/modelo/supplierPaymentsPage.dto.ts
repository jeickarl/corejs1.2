import { ApiProperty } from '@nestjs/swagger';
import { SupplierPaymentDto } from './supplierPayment.dto';

export class SupplierPaymentsPageDto {
  @ApiProperty({ type: [SupplierPaymentDto] })
  items!: SupplierPaymentDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 0 })
  total!: number;
}

