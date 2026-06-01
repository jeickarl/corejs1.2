import { ApiProperty } from '@nestjs/swagger';
import { BillingReportRowDto } from './billingReportRow.dto';
import { BillingReportTotalsDto } from './billingReportTotals.dto';

export class BillingReportPageDto {
  @ApiProperty({ type: [BillingReportRowDto] })
  items!: BillingReportRowDto[];

  @ApiProperty()
  page!: number;

  @ApiProperty()
  perPage!: number;

  @ApiProperty()
  total!: number;

  @ApiProperty({ type: BillingReportTotalsDto })
  totals!: BillingReportTotalsDto;
}

